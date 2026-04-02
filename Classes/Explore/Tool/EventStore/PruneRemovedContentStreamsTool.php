<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\EventStore;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\Service\ContentStreamPrunerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\ContentRepository\DynamicContentRepositoryRegistrar;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\Tool\WithContextChangeInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;

/**
 * @internal Wraps {@see \Neos\ContentRepository\Core\Service\ContentStreamPruner::pruneRemovedFromEventStream()},
 *           deleting all event-stream history for content streams already marked removed (i.e. old
 *           workspace snapshots created during publish/discard/rebase). This is irreversible.
 *
 *           Distinct from {@see CompactEventsTool}, which merges duplicate property-edit events
 *           *within* live streams. This tool removes entire content stream histories for streams
 *           that are no longer referenced by any workspace.
 *
 *           Especially useful after {@see CrCopyTool}: a copied CR carries all obsolete streams,
 *           so pruning drastically reduces event count for experimentation.
 */
#[ToolMeta(shortName: 'pruneRemovedContentStreams', group: 'Events')]
final class PruneRemovedContentStreamsTool implements ToolInterface, WithContextChangeInterface
{
    public function __construct(
        private readonly ContentRepositoryRegistry $crRegistry,
        private readonly DynamicContentRepositoryRegistrar $registrar,
        private readonly Connection $dbal,
        private readonly ContentRepositoryId $cr,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Prune event stream: delete removed/discarded content stream histories (⚠ irreversible)';
    }

    /**
     * Shows a tip when the CR changes and it has pruneable removed content streams.
     * Fires once on session bootstrap (old = empty context) and on every subsequent CR switch.
     * Silently skips if the CR is not yet usable (e.g. an unregistered dynamic CR on bootstrap).
     */
    public function onContextChange(ToolContext $old, ToolContext $new, ToolIOInterface $io): void
    {
        // Only suggest when the CR itself changed (not on workspace/node/dsp changes)
        $oldCr = $old->getByType(ContentRepositoryId::class);
        if ($oldCr instanceof ContentRepositoryId && $oldCr->value === $this->cr->value) {
            return;
        }

        // Guard: CR may not be usable yet (dynamic and not yet registered in pass 1).
        // Silently skip — the suggestion will appear the next time the user changes CR.
        try {
            $pruner = $this->crRegistry->buildService($this->cr, new ContentStreamPrunerFactory());
        } catch (\Throwable) {
            return;
        }

        if ($this->countPruneableStreams($pruner) > 0) {
            $io->writeNote(sprintf(
                'Tip: CR "%s" has removed content streams. Run "pruneRemovedContentStreams" to delete their event history.',
                $this->cr->value,
            ));
        }
    }

    /** Counts content streams that can be pruned by calling outputStatus() silently. */
    private function countPruneableStreams(object $pruner): int
    {
        $count = 0;
        $inSection = false;
        $pruner->outputStatus(static function(string $line = '') use (&$count, &$inSection): void {
            if (str_contains($line, 'can be pruned from the event stream')) {
                $inSection = true;
            }
            if ($inSection && str_starts_with($line, '  id: ')) {
                $count++;
            }
        });
        return $count;
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $pruner = $this->crRegistry->buildService($this->cr, new ContentStreamPrunerFactory());
        $eventsTable = DoctrineEventStoreFactory::databaseTableName($this->cr);

        // --- Warn if targeting a production CR (informational only — single confirm below) ---
        if ($this->registrar->isRegistered($this->cr)) {
            $io->writeNote(sprintf(
                'Warning: "%s" is a production CR registered in Flow settings.',
                $this->cr->value
            ));
            $io->writeLine('Run crCopy first to create a shadow CR and prune there.');
        }

        // --- Count pruneable and total content streams (silently, without displaying the full list) ---
        $totalContentStreams = (int)$this->dbal->fetchOne(
            "SELECT COUNT(DISTINCT stream) FROM {$eventsTable} WHERE stream LIKE :prefix",
            ['prefix' => ContentStreamEventStreamName::EVENT_STREAM_NAME_PREFIX . '%']
        );

        $pruneableCount = $this->countPruneableStreams($pruner);

        if ($pruneableCount === 0) {
            $io->writeInfo(sprintf(
                'No pruneable content streams in CR "%s" (%d total). Nothing to do.',
                $this->cr->value,
                $totalContentStreams,
            ));
            return null;
        }

        $io->writeLine(sprintf(
            '%d content stream(s) to be pruned (of %d total) in CR "%s".',
            $pruneableCount,
            $totalContentStreams,
            $this->cr->value,
        ));

        // --- Count events before pruning ---
        $eventsBefore = (int)$this->dbal->fetchOne("SELECT COUNT(*) FROM {$eventsTable}");

        // --- Single confirmation ---
        if (!$io->confirm(sprintf('Prune removed content streams from CR "%s"? (⚠ permanently deletes event history)', $this->cr->value))) {
            $io->writeLine('Aborted.');
            return null;
        }

        // --- Execute pruning inside a task widget with live log output ---
        $prunedCount = 0;
        $io->task(
            sprintf('Pruning %d content stream(s) from CR "%s"', $pruneableCount, $this->cr->value),
            static function(callable $log) use ($pruner, &$prunedCount): void {
                $pruner->pruneRemovedFromEventStream(static function(string $line = '') use ($log, &$prunedCount): void {
                    if ($line !== '') {
                        $log($line);
                    }
                    if (str_starts_with($line, 'Removed events for ')) {
                        $prunedCount++;
                    }
                });
            }
        );

        // --- Summary: event count before/after ---
        $eventsAfter = (int)$this->dbal->fetchOne("SELECT COUNT(*) FROM {$eventsTable}");

        $io->writeInfo(sprintf(
            'Done. Pruned %d content stream(s), removed %d event(s) (events: %d → %d).',
            $prunedCount,
            $eventsBefore - $eventsAfter,
            $eventsBefore,
            $eventsAfter,
        ));

        return null;
    }
}
