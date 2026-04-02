<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\EventStore;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetSerializedNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\ContentRepository\DynamicContentRepositoryRegistrar;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\InternalServices\EventStoreDebuggingInternalsFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

/**
 * @internal Folds consecutive NodePropertiesWereSet streaks into a single event per node, reducing
 *           event-log size without changing projection state. Also merges the commandPayload in
 *           event metadata so workspace-rebase operations remain correct.
 *
 *           Run on a shadow CR (via {@see CrCopyTool}) — warns when targeting a production CR.
 *           Follow up with 'resetProjections' + 'catchUp' or ./flow subscription:replayAll.
 *
 * Algorithm overview
 * ------------------
 *
 * Input: the full ordered event log of a CR (sequence numbers 1…N), streamed one by one.
 *
 * A *streak* is a maximal contiguous run of NodePropertiesWereSet events. ANY other event type
 * (NodeAggregateWasTagged, NodeAggregateWithNodeWasCreated, …) immediately ends the current
 * streak and all its open groups are flushed/compacted right away.
 *
 * Within a streak, events are grouped by a composite key:
 *
 *   groupKey = streamName + ":" + nodeAggregateId + ":" + originDimensionSpacePoint.hash
 *
 * Example walkthrough (■ = NodePropertiesWereSet, □ = any other event type, A/B = groupKey):
 *
 *   seq 1:  ■  key=A  ─┐
 *   seq 2:  ■  key=A  ─┘  streak 1 ends at seq 3 → group A=[1,2]    → compacted (2 events)
 *   seq 3:  □              ← streak break: ALL open groups flushed immediately
 *   seq 4:  ■  key=B  ─┐
 *   seq 5:  ■  key=A   │  streak 2 ends at seq 7 → group B=[4,6]     → compacted (2 events)
 *   seq 6:  ■  key=B  ─┘                           group A=[5]       → skipped  (1 event)
 *   seq 7:  □              ← streak break: ALL open groups flushed immediately
 *   seq 8:  ■  key=A  ─┐
 *   seq 9:  ■  key=A  ─┘  end of log → group A=[8,9]                → compacted (2 events)
 *
 * Consistency guard: a group is only compactable if ALL its events agree on commandClass
 * (presence AND exact value). A mismatch splits the group at that point — the existing
 * sub-group is compacted immediately and a fresh one begins for the same key.
 *
 * For each compactable group the result is ONE surviving event (the last in the group):
 *
 *   group=[seq1, seq2, seq3]  →  UPDATE seq3 with merged payload+metadata; DELETE seq1, seq2
 *
 * Merged payload:  NodePropertiesWereSet::mergeProperties() fold left-to-right (last wins).
 * Merged metadata: last event's metadata is the base; commandPayload is rebuilt from the
 *                  merged domain event; initiatingUserId/Timestamp come from the last event
 *                  (it represents the session that produced the final state).
 *
 * Idempotency: UPDATE-then-DELETE — if interrupted, the last event already holds the correct
 * merged state and re-running simply re-deletes the already-correct predecessors.
 *
 * Memory: only the *current streak* is buffered; events are never fully loaded into an array.
 *
 * @see EventGraveyardTool for a similar raw-DB mutation pattern
 */
#[ToolMeta(shortName: 'compactEvents', group: 'Events')]
final class CompactEventsTool implements ToolInterface
{
    public function __construct(
        private readonly ContentRepositoryRegistry $crRegistry,
        private readonly Connection $dbal,
        private readonly DynamicContentRepositoryRegistrar $registrar,
        private readonly ContentRepositoryId $cr,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Compact events: merge property-edit duplicates within live streams (⚠ modifies event store)';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        // --- Step 1: Warn if targeting a production CR (informational only — single confirm below) ---
        if ($this->registrar->isRegistered($this->cr)) {
            $io->writeNote(sprintf(
                'Warning: "%s" is a production CR registered in Flow settings.',
                $this->cr->value
            ));
            $io->writeLine('Run crCopy first to create a shadow CR and run compaction there.');
        }

        // --- Step 2: Confirm compaction ---
        if (!$io->confirm(sprintf('Compact NodePropertiesWereSet streaks in CR "%s"? (⚠ modifies event store)', $this->cr->value))) {
            $io->writeLine('Aborted.');
            return null;
        }

        // --- Step 3: Prepare internals (events are streamed below, not buffered) ---
        $internals = $this->crRegistry->buildService($this->cr, new EventStoreDebuggingInternalsFactory());
        $eventsTable = DoctrineEventStoreFactory::databaseTableName($this->cr);
        $totalEvents = (int)$this->dbal->fetchOne("SELECT COUNT(*) FROM {$eventsTable}");
        $deletedCount = 0;
        $groupCount = 0;

        // --- Step 4+5: Stream events; compact each streak's groups as the streak closes ---
        //
        // $pendingGroups — open groups in the *current* streak, keyed by groupKey.
        //                  Reset to [] each time a streak ends (non-NPWS event encountered).
        //
        /** @var array<string, list<EventEnvelope>> $pendingGroups */
        $pendingGroups = [];

        // Extract commandClass from event metadata (null when absent).
        $commandClassOf = static fn(EventEnvelope $e): ?string => $e->event->metadata?->value['commandClass'] ?? null;

        // Compact one finalized group immediately (UPDATE last event, DELETE predecessors).
        $compactGroup = function (array $group) use (&$deletedCount, &$groupCount, $eventsTable, $internals): void {
            if (count($group) < 2) {
                return; // single-event groups are not compactable
            }

            // Merge domain events left-to-right: later property values win.
            /** @var NodePropertiesWereSet $mergedDomainEvent */
            $mergedDomainEvent = $internals->eventNormalizer->denormalize($group[0]->event);
            for ($i = 1; $i < count($group); $i++) {
                /** @var NodePropertiesWereSet $next */
                $next = $internals->eventNormalizer->denormalize($group[$i]->event);
                $mergedDomainEvent = $mergedDomainEvent->mergeProperties($next);
            }

            // Base merged metadata on the LAST event — it represents the final user session
            // (initiatingUserId, initiatingTimestamp, etc.).
            /** @var array<string, mixed> $mergedMetadata */
            $mergedMetadata = $group[count($group) - 1]->event->metadata?->value ?? [];

            // Rebuild commandPayload from the merged domain event so workspace-rebase replays
            // the correct final state (not the intermediate payload of the last event alone).
            if (isset($group[0]->event->metadata?->value['commandClass'])) {
                $mergedCommand = SetSerializedNodeProperties::create(
                    workspaceName: $mergedDomainEvent->workspaceName,
                    nodeAggregateId: $mergedDomainEvent->nodeAggregateId,
                    originDimensionSpacePoint: $mergedDomainEvent->originDimensionSpacePoint,
                    propertyValues: $mergedDomainEvent->propertyValues,
                    propertiesToUnset: $mergedDomainEvent->propertiesToUnset,
                );
                $mergedMetadata['commandClass'] = SetSerializedNodeProperties::class;
                $mergedMetadata['commandPayload'] = $mergedCommand->jsonSerialize();
            }

            // Record how many events were folded into this one (aids debugging).
            $mergedMetadata['compacted_from_count'] = count($group);

            $mergedPayload = json_encode($mergedDomainEvent, JSON_THROW_ON_ERROR);
            $mergedMetaJson = json_encode(
                array_filter($mergedMetadata, static fn($v) => $v !== null),
                JSON_THROW_ON_ERROR
            );

            // UPDATE-then-DELETE: last event gets the merged state before predecessors are removed.
            $lastSequenceNumber = end($group)->sequenceNumber->value;
            $predecessorSequenceNumbers = array_map(
                static fn(EventEnvelope $e) => $e->sequenceNumber->value,
                array_slice($group, 0, -1)
            );

            $this->dbal->transactional(function () use ($eventsTable, $lastSequenceNumber, $mergedPayload, $mergedMetaJson, $predecessorSequenceNumbers): void {
                $this->dbal->executeStatement(
                    "UPDATE {$eventsTable} SET payload = :payload, metadata = :meta WHERE sequencenumber = :seq",
                    ['payload' => $mergedPayload, 'meta' => $mergedMetaJson, 'seq' => $lastSequenceNumber]
                );
                $placeholders = implode(',', array_fill(0, count($predecessorSequenceNumbers), '?'));
                $this->dbal->executeStatement(
                    "DELETE FROM {$eventsTable} WHERE sequencenumber IN ({$placeholders})",
                    $predecessorSequenceNumbers
                );
            });

            $deletedCount += count($predecessorSequenceNumbers);
            $groupCount++;
        };

        // Flush all open groups when a streak ends (called on non-NPWS events and at end-of-stream).
        $flushStreak = function () use (&$pendingGroups, $compactGroup): void {
            foreach ($pendingGroups as $group) {
                $compactGroup($group);
            }
            $pendingGroups = [];
        };

        $io->progress(
            sprintf('Scanning %d event(s) in CR "%s" …', $totalEvents, $this->cr->value),
            $totalEvents,
            function (callable $advance) use ($internals, $commandClassOf, &$pendingGroups, $compactGroup, $flushStreak): void {
                foreach ($internals->eventStore->load(VirtualStreamName::all()) as $envelope) {
                    $advance();
                    $domainEvent = $internals->eventNormalizer->denormalize($envelope->event);
                    if (!$domainEvent instanceof NodePropertiesWereSet) {
                        // Any non-NodePropertiesWereSet event ends all open groups immediately.
                        $flushStreak();
                        continue;
                    }

                    // Composite key that identifies events belonging to the same logical edit.
                    $groupKey = $envelope->streamName->value
                        . ':' . $domainEvent->nodeAggregateId->value
                        . ':' . $domainEvent->originDimensionSpacePoint->hash;

                    // Consistency guard: commandClass mismatch → finalize existing sub-group, start fresh.
                    if (isset($pendingGroups[$groupKey]) && $commandClassOf($pendingGroups[$groupKey][0]) !== $commandClassOf($envelope)) {
                        $compactGroup($pendingGroups[$groupKey]);
                        $pendingGroups[$groupKey] = [];
                    }

                    $pendingGroups[$groupKey][] = $envelope;
                }
                $flushStreak(); // flush the final streak at end-of-stream
            }
        );

        // --- Step 6: Statistics ---
        if ($deletedCount === 0) {
            $io->writeInfo(sprintf('Nothing to compact in CR "%s".', $this->cr->value));
            return null;
        }

        $io->writeInfo(sprintf(
            'Compacted %d group(s), deleted %d event(s) from CR "%s".',
            $groupCount,
            $deletedCount,
            $this->cr->value
        ));
        $io->writeNote('Projections are now out of sync. Run "resetProjections" then "catchUp", or: ./flow subscription:replayAll');

        return null;
    }
}
