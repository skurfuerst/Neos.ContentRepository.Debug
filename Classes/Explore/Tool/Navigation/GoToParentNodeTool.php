<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Navigation;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;

/**
 * @internal Shows all ancestor nodes as a breadcrumb path and lets the user navigate to any of them.
 *
 * @see ContentSubgraphInterface::findAncestorNodes() for the underlying lookup.
 */
#[ToolMeta(shortName: 'pn', group: 'Nodes')]
final class GoToParentNodeTool implements ToolInterface
{
    public function __construct(
        private readonly NodeLabelGeneratorInterface $nodeLabelGenerator,
        private readonly ToolContext $context,
        private readonly ContentSubgraphInterface $subgraph,
        private readonly NodeAggregateId $node,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Navigate to ancestor';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $ancestors = $this->subgraph->findAncestorNodes($this->node, FindAncestorNodesFilter::create());
        $ancestorList = iterator_to_array($ancestors);

        if ($ancestorList === []) {
            $io->writeError('Current node has no ancestors (root node).');
            return null;
        }

        // findAncestorNodes returns closest-first; reverse to show root→…→parent (natural tree order)
        $ancestorList = array_reverse($ancestorList);

        $io->writeLine('');
        $tableRows = ['_stay' => ['(stay here)', '', '', '']];
        foreach ($ancestorList as $i => $ancestor) {
            $id = $ancestor->aggregateId->value;
            $indent = str_repeat('  ', $i);
            $tableRows[$id] = [$indent . $this->nodeLabel($ancestor), $ancestor->name?->value ?? '-', $ancestor->nodeTypeName->value, $id];
        }

        $selected = $io->chooseFromTable('Navigate to ancestor', ['Label', 'Name', 'Type', 'ID'], $tableRows);
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
