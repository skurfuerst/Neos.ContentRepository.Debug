<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;

/**
 * @internal Lists direct child nodes and lets the user navigate into one.
 *
 * @see ContentSubgraphInterface::findChildNodes() for the underlying lookup.
 */
#[ToolMeta(shortName: 'cn', group: 'Nodes')]
final class ChildNodesTool implements ToolInterface
{
    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: children';
    }

    public function execute(ToolIOInterface $io, ToolContext $context, ContentSubgraphInterface $subgraph, NodeAggregateId $node): ?ToolContext
    {
        $children = $subgraph->findChildNodes($node, FindChildNodesFilter::create());

        if ($children->count() === 0) {
            $io->writeLine('No child nodes.');
            return null;
        }

        $tableRows = ['_stay' => ['(stay here)', '', '', '']];
        foreach ($children as $child) {
            $id = $child->aggregateId->value;
            $tableRows[$id] = [$this->nodeLabel($child), $child->name?->value ?? '–', $child->nodeTypeName->value, $id];
        }

        $selected = $io->chooseFromTable('Navigate to child', ['Label', 'Name', 'Type', 'ID'], $tableRows);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeInfo(sprintf('✔ Node set to: %s', $selected));
        return $context->with('node', NodeAggregateId::fromString($selected));
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
