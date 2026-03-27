<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Workspace;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\CommandHandler\CommandSimulatorFactory;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNodeAndSerializedProperties;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetSerializedNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Command\ChangeNodeAggregateType;
use Neos\ContentRepository\Core\Feature\RebaseableCommand;
use Neos\ContentRepository\Core\Feature\RebaseableCommands;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandHandler;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvent;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\ObjectAccess;

/**
 * @internal Simulates a partial workspace publish against the base workspace using the production
 *           {@see CommandSimulatorFactory} (accessed via reflection) to detect ordering conflicts
 *           before they surface as {@see \Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\PartialWorkspaceRebaseFailed}.
 *
 * @see WorkspaceCommandHandler::handlePublishIndividualNodesFromWorkspace for the production logic this mirrors
 */
#[ToolMeta(shortName: 'simPartialPublish', group: 'Workspace')]
#[Flow\Scope('singleton')]
final class WorkspacePartialPublishSimulatorTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        if ($context->getByType(WorkspaceName::class) === null) {
            return 'Workspace: simulate partial publish (select a workspace first)';
        }
        return 'Workspace: simulate partial publish';
    }

    public function execute(
        ToolIOInterface $io,
        ContentRepositoryId $cr,
        WorkspaceName $workspace,
        ContentRepository $contentRepository,
        EventStoreInterface $eventStore,
    ): ?ToolContext {
        // --- Step 1: Extract CommandSimulatorFactory from production wiring via reflection ---
        $commandBus = ObjectAccess::getProperty($contentRepository, 'commandBus', forceDirectAccess: true);
        $handlers = ObjectAccess::getProperty($commandBus, 'handlers', forceDirectAccess: true);
        $commandSimulatorFactory = null;
        foreach ($handlers as $handler) {
            if ($handler instanceof WorkspaceCommandHandler) {
                $commandSimulatorFactory = ObjectAccess::getProperty($handler, 'commandSimulatorFactory', forceDirectAccess: true);
                break;
            }
        }
        if (!$commandSimulatorFactory instanceof CommandSimulatorFactory) {
            $io->writeError('Could not extract CommandSimulatorFactory from ContentRepository internals.');
            return null;
        }

        // --- Step 2: Load rebaseable commands from workspace event stream ---
        $ws = $contentRepository->findWorkspaceByName($workspace);
        if ($ws === null) {
            $io->writeError(sprintf('Workspace "%s" not found.', $workspace->value));
            return null;
        }
        if ($ws->baseWorkspaceName === null) {
            $io->writeError(sprintf('"%s" is a root workspace (no base workspace). Partial publish is not applicable.', $workspace->value));
            return null;
        }

        $stream = $eventStore->load(
            ContentStreamEventStreamName::fromContentStreamId($ws->currentContentStreamId)
                ->getEventStreamName()
        );
        $rebaseableCommands = RebaseableCommands::extractFromEventStream($stream);

        if ($rebaseableCommands->isEmpty()) {
            $io->writeLine('No rebaseable commands found in this workspace.');
            return null;
        }

        // --- Step 3: Display all commands ---
        $io->writeNote(sprintf('Rebaseable commands in workspace "%s"', $workspace->value));
        $io->writeTable(
            ['Seq#', 'Command', 'Node aggregate ID', 'Node type'],
            array_map(
                fn(RebaseableCommand $cmd) => [
                    $cmd->originalSequenceNumber->value,
                    $this->shortClassName($cmd->originalCommand::class),
                    $this->nodeAggregateIdOf($cmd),
                    $this->nodeTypeOf($cmd),
                ],
                iterator_to_array($rebaseableCommands)
            )
        );

        // --- Step 4: Accept node IDs to publish ---
        $input = trim($io->ask('Enter nodeAggregateIds to include (comma-separated, blank = all)'));

        if ($input === '') {
            $nodeIds = NodeAggregateIds::fromArray(
                array_map(
                    fn(RebaseableCommand $cmd) => $this->nodeAggregateIdOf($cmd),
                    iterator_to_array($rebaseableCommands)
                )
            );
        } else {
            $nodeIds = NodeAggregateIds::fromArray(
                array_map(trim(...), explode(',', $input))
            );
        }

        [$matchingCommands, $remainingCommands] = $rebaseableCommands->separateMatchingAndRemainingCommands($nodeIds);

        if ($matchingCommands->isEmpty()) {
            $io->writeError('No commands match those node IDs.');
            return null;
        }

        // --- Step 5: Display split ---
        $io->writeLine('');
        $io->writeNote(sprintf('Matching (%d commands — would be published)', iterator_count($matchingCommands->getIterator())));
        $io->writeTable(
            ['Seq#', 'Command', 'Node aggregate ID', 'Node type'],
            array_map(
                fn(RebaseableCommand $cmd) => [
                    $cmd->originalSequenceNumber->value,
                    $this->shortClassName($cmd->originalCommand::class),
                    $this->nodeAggregateIdOf($cmd),
                    $this->nodeTypeOf($cmd),
                ],
                iterator_to_array($matchingCommands)
            )
        );

        $io->writeNote(sprintf('Remaining (%d commands — would stay in workspace)', iterator_count($remainingCommands->getIterator())));
        if (!$remainingCommands->isEmpty()) {
            $io->writeTable(
                ['Seq#', 'Command', 'Node aggregate ID', 'Node type'],
                array_map(
                    fn(RebaseableCommand $cmd) => [
                        $cmd->originalSequenceNumber->value,
                        $this->shortClassName($cmd->originalCommand::class),
                        $this->nodeAggregateIdOf($cmd),
                        $this->nodeTypeOf($cmd),
                    ],
                    iterator_to_array($remainingCommands)
                )
            );
        } else {
            $io->writeLine('(none)');
        }

        // Build a lookup map from originalSequenceNumber to RebaseableCommand so we can retrieve
        // original event payloads for conflict drill-down without re-querying the event store.
        /** @var array<int, RebaseableCommand> $commandByOriginalSeq */
        $commandByOriginalSeq = [];
        foreach ($matchingCommands as $cmd) {
            $commandByOriginalSeq[$cmd->originalSequenceNumber->value] = $cmd;
        }
        foreach ($remainingCommands as $cmd) {
            $commandByOriginalSeq[$cmd->originalSequenceNumber->value] = $cmd;
        }

        // --- Step 6: Run simulation ---
        $baseWorkspace = $contentRepository->findWorkspaceByName($ws->baseWorkspaceName);
        if ($baseWorkspace === null) {
            $io->writeError(sprintf('Base workspace "%s" not found.', $ws->baseWorkspaceName->value));
            return null;
        }

        $io->writeLine('');
        $io->writeNote(sprintf('Running simulation against base workspace "%s"…', $baseWorkspace->workspaceName->value));

        $simulator = $commandSimulatorFactory->createSimulatorForWorkspace($baseWorkspace->workspaceName);

        /** @var SequenceNumber $highestSeqForMatching */
        $highestSeqForMatching = $simulator->run(
            static function (callable $handle) use ($simulator, $matchingCommands, $remainingCommands): SequenceNumber {
                foreach ($matchingCommands as $cmd) {
                    $handle($cmd);
                }
                $highest = $simulator->currentSequenceNumber();
                foreach ($remainingCommands as $cmd) {
                    $handle($cmd);
                }
                return $highest;
            }
        );

        // --- Step 7: Display results ---
        if ($simulator->hasConflicts()) {
            $conflicts = $simulator->getConflictingEvents();
            $io->writeNote(sprintf('⚠ Simulation conflicts (%d):', count($conflicts)));
            $io->writeLine('');
            foreach ($conflicts as $conflict) {
                /** @var ConflictingEvent $conflict */
                $nodeId = $conflict->getAffectedNodeAggregateId()?->value ?? '—';
                $io->writeLine(sprintf(
                    '  seq %-8s  %s  [node: %s]',
                    $conflict->getSequenceNumber()->value,
                    $this->shortClassName($conflict->getEvent()::class),
                    $nodeId,
                ));
                $io->writeLine(sprintf('             %s', $conflict->getException()->getMessage()));
                $io->writeLine('');
            }

            // --- Conflict drill-down ---
            $conflictChoices = [];
            $conflictItems = [];
            foreach ($conflicts as $i => $conflict) {
                /** @var ConflictingEvent $conflict */
                $key = (string)$i;
                $conflictChoices[$key] = sprintf(
                    'seq %s — %s [node: %s]',
                    $conflict->getSequenceNumber()->value,
                    $this->shortClassName($conflict->getEvent()::class),
                    $conflict->getAffectedNodeAggregateId()?->value ?? '—',
                );
                $conflictItems[$key] = $conflict;
            }
            $selectedConflicts = $io->chooseMultiple('Inspect conflict details (blank = none)', $conflictChoices);
            foreach ($selectedConflicts as $key) {
                $conflict = $conflictItems[$key];
                $seq = $conflict->getSequenceNumber()->value;
                $io->writeNote(sprintf('## Conflict seq %s — %s', $seq, $this->shortClassName($conflict->getEvent()::class)));
                $originalCmd = $commandByOriginalSeq[$seq] ?? null;
                if ($originalCmd !== null) {
                    $io->writeNote('Original event payload:');
                    $this->writePayload($io, $originalCmd->originalEvent->data->value);
                }
                $io->writeNote('Exception:');
                $io->writeKeyValue($this->formatException($conflict->getException()));
            }
        }

        $matchingCount = iterator_count($matchingCommands->getIterator());

        if (!$highestSeqForMatching->equals(SequenceNumber::none())) {
            $io->writeLine('');
            if ($simulator->hasConflicts()) {
                $io->writeNote('Events that were simulated until the conflict happened:');
            } else {
                $io->writeNote('Events that would be published to base workspace:');
            }

            $rows = [];
            foreach ($simulator->eventStream()->withMaximumSequenceNumber($highestSeqForMatching) as $envelope) {
                $payload = json_decode($envelope->event->data->value, true);
                $nodeId = is_array($payload) ? ($payload['nodeAggregateId'] ?? '—') : '—';
                $rows[] = [
                    $envelope->sequenceNumber->value,
                    $this->shortClassName($envelope->event->type->value),
                    $nodeId,
                ];
            }
            $io->writeTable(['In-mem seq', 'Event type', 'Node aggregate ID'], $rows);

            // --- Event payload drill-down ---
            $eventChoices = [];
            $eventEnvelopes = [];
            foreach ($simulator->eventStream()->withMaximumSequenceNumber($highestSeqForMatching) as $envelope) {
                $key = (string)$envelope->sequenceNumber->value;
                $payload = json_decode($envelope->event->data->value, true);
                $nodeId = is_array($payload) ? ($payload['nodeAggregateId'] ?? '—') : '—';
                $eventChoices[$key] = sprintf(
                    '[%s] %s / node: %s',
                    $envelope->sequenceNumber->value,
                    $this->shortClassName($envelope->event->type->value),
                    $nodeId,
                );
                $eventEnvelopes[$key] = $envelope;
            }
            if ($eventChoices !== []) {
                $selectedEvents = $io->chooseMultiple('Inspect event payloads in detail (blank = none)', $eventChoices);
                foreach ($selectedEvents as $key) {
                    $envelope = $eventEnvelopes[$key];
                    $io->writeNote(sprintf('## Event seq %s — %s', $key, $this->shortClassName($envelope->event->type->value)));
                    $this->writePayload($io, $envelope->event->data->value);
                }
            }
        } else {
            $io->writeLine('Matching commands produced no events.');
        }

        return null;
    }

    /**
     * Returns the short class/type name (last segment after backslash or colon).
     */
    private function shortClassName(string $fqcn): string
    {
        $pos = max(strrpos($fqcn, '\\'), strrpos($fqcn, ':'));
        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }

    private function nodeAggregateIdOf(RebaseableCommand $cmd): string
    {
        $command = $cmd->originalCommand;
        if ($command instanceof SetSerializedNodeReferences) {
            return $command->sourceNodeAggregateId->value;
        }
        // All other rebaseable commands have a nodeAggregateId property
        return $command->nodeAggregateId->value; // @phpstan-ignore property.notFound
    }

    private function nodeTypeOf(RebaseableCommand $cmd): string
    {
        $command = $cmd->originalCommand;
        if ($command instanceof CreateNodeAggregateWithNodeAndSerializedProperties) {
            return $command->nodeTypeName->value;
        }
        if ($command instanceof ChangeNodeAggregateType) {
            return '→ ' . $command->newNodeTypeName->value;
        }
        return '—';
    }

    private function writePayload(ToolIOInterface $io, string $json): void
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            $io->writeLine('(payload could not be decoded)');
            return;
        }
        $pairs = [];
        foreach ($payload as $key => $value) {
            $pairs[(string)$key] = is_array($value) || is_object($value)
                ? (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$value;
        }
        $io->writeKeyValue($pairs);
    }

    /**
     * @return array<string, string>
     */
    private function formatException(\Throwable $e): array
    {
        $frames = array_slice($e->getTrace(), 0, 10);
        $traceLines = array_map(
            static function (array $frame): string {
                $location = isset($frame['file']) ? basename($frame['file']) . ':' . ($frame['line'] ?? '?') : '(internal)';
                $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?') . '()';
                return $call . ' at ' . $location;
            },
            $frames,
        );

        $pairs = [
            'class'     => $e::class,
            'message'   => $e->getMessage(),
            'file:line' => $e->getFile() . ':' . $e->getLine(),
            'trace'     => implode("\n", $traceLines),
        ];

        $prev = $e->getPrevious();
        if ($prev !== null) {
            $pairs['previous.class']    = $prev::class;
            $pairs['previous.message']  = $prev->getMessage();
            $pairs['previous.file:line'] = $prev->getFile() . ':' . $prev->getLine();
            $prevPrev = $prev->getPrevious();
            if ($prevPrev !== null) {
                $pairs['previous.previous'] = $prevPrev::class . ': ' . $prevPrev->getMessage();
            }
        }

        return $pairs;
    }
}
