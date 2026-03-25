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
        $io->writeLine(sprintf('<comment>Rebaseable commands in workspace "%s"</comment>', $workspace->value));
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
        $io->writeLine(sprintf('<comment>Matching (%d commands — would be published)</comment>', iterator_count($matchingCommands->getIterator())));
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

        $io->writeLine(sprintf('<comment>Remaining (%d commands — would stay in workspace)</comment>', iterator_count($remainingCommands->getIterator())));
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

        // --- Step 6: Run simulation ---
        $baseWorkspace = $contentRepository->findWorkspaceByName($ws->baseWorkspaceName);
        if ($baseWorkspace === null) {
            $io->writeError(sprintf('Base workspace "%s" not found.', $ws->baseWorkspaceName->value));
            return null;
        }

        $io->writeLine('');
        $io->writeLine(sprintf('<comment>Running simulation against base workspace "%s"…</comment>', $baseWorkspace->workspaceName->value));

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
            $io->writeLine(sprintf('⚠ Simulation conflicts (%d):', count($conflicts)));
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
        }

        $matchingCount = iterator_count($matchingCommands->getIterator());

        if (!$highestSeqForMatching->equals(SequenceNumber::none())) {
            $io->writeLine('');
            if ($simulator->hasConflicts()) {
                $io->writeLine('<comment>Events that were simulated until the conflict happened:</comment>');
            } else {
                $io->writeLine('<comment>Events that would be published to base workspace:</comment>');
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
}
