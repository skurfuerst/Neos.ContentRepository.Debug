<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;

/**
 * @internal Shows outgoing and incoming references for a node, with navigation into referenced nodes.
 *
 * @see ContentSubgraphInterface::findReferences() for outgoing references.
 * @see ContentSubgraphInterface::findBackReferences() for incoming references.
 */
#[ToolMeta(shortName: 'nRefs', group: 'Nodes')]
final class NodeReferencesTool implements ToolInterface
{
    public function __construct(
        private readonly NodeLabelGeneratorInterface $nodeLabelGenerator,
        private readonly ToolContext $context,
        private readonly ContentSubgraphInterface $subgraph,
        private readonly NodeAggregateId $node,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: references';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $outgoing = $this->subgraph->findReferences($this->node, FindReferencesFilter::create());
        $incoming = $this->subgraph->findBackReferences($this->node, FindBackReferencesFilter::create());

        if (count($outgoing) === 0 && count($incoming) === 0) {
            $io->writeLine('No references.');
            return null;
        }

        $io->writeNote(sprintf('%d outgoing, %d incoming references', count($outgoing), count($incoming)));

        $tableRows = ['_stay' => ['(stay here)', '', '', '', '']];
        foreach ($outgoing as $ref) {
            $targetId = $ref->node->aggregateId->value;
            $tableRows[$targetId] = [
                '→',
                $ref->name->value,
                $this->nodeLabel($ref->node),
                $ref->node->nodeTypeName->value,
                $targetId,
            ];
        }
        foreach ($incoming as $ref) {
            $sourceId = $ref->node->aggregateId->value;
            $tableRows[$sourceId] = [
                '←',
                $ref->name->value,
                $this->nodeLabel($ref->node),
                $ref->node->nodeTypeName->value,
                $sourceId,
            ];
        }

        $selected = $io->chooseFromTable('Navigate to referenced node', ['Dir', 'Reference', 'Label', 'Type', 'Node ID'], $tableRows);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeInfo(sprintf('✔ Node set to: %s', $selected));
        return $this->context->with('node', NodeAggregateId::fromString($selected));
    }

    private function nodeLabel(Node $node): string
    {
        try {
            return $this->nodeLabelGenerator->getLabel($node);
        } catch (\Throwable) {
            return $node->nodeTypeName->value . ' (' . ($node->name?->value ?? '-') . ')';
        }
    }
}
