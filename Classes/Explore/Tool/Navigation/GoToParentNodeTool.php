<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Navigation;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Shows all ancestor nodes as a breadcrumb path and lets the user navigate to any of them.
 *
 * @see ContentSubgraphInterface::findAncestorNodes() for the underlying lookup.
 */
final class GoToParentNodeTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Navigate to ancestor';
    }

    public function execute(ToolIOInterface $io, ToolContext $context, ContentSubgraphInterface $subgraph, NodeAggregateId $node): ?ToolContext
    {
        $ancestors = $subgraph->findAncestorNodes($node, FindAncestorNodesFilter::create());
        $ancestorList = iterator_to_array($ancestors);

        if ($ancestorList === []) {
            $io->writeError('Current node has no ancestors (root node).');
            return null;
        }

        // findAncestorNodes returns closest-first; reverse to show root→…→parent (natural tree order)
        $ancestorList = array_reverse($ancestorList);

        $io->writeLine('');
        $rows = [];
        $choices = ['_stay' => '(stay here)'];
        $depth = count($ancestorList);
        foreach ($ancestorList as $i => $ancestor) {
            $id = $ancestor->aggregateId->value;
            $name = $ancestor->name?->value ?? '-';
            $type = $ancestor->nodeTypeName->value;
            $indent = str_repeat('  ', $i);
            $rows[] = [$indent . $name, $type, $id];
            $choices[$id] = sprintf('%s%s (%s)', $indent, $name, $type);
            $depth--;
        }

        $io->writeTable(['Name', 'Type', 'ID'], $rows);

        $selected = $io->choose('Navigate to ancestor', $choices);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeLine(sprintf('✔ Node set to: %s', $selected));
        return $context->with('node', NodeAggregateId::fromString($selected));
    }
}
