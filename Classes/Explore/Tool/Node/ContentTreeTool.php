<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Renders the full content tree (all node types) under the current node — shows the page structure
 *           including content collections, content nodes, etc.
 *
 * @see ContentSubgraphInterface::findSubtree() for the underlying tree lookup.
 */
#[ToolMeta(shortName: 'nContentTree', group: 'Nodes')]
final class ContentTreeTool implements ToolInterface
{
    private const MAX_LEVELS = 5;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: content tree';
    }

    public function execute(
        ToolIOInterface $io,
        ToolContext $context,
        ContentSubgraphInterface $subgraph,
        NodeAggregateId $node,
    ): ?ToolContext {
        // Show content structure only — exclude document descendants (subpages)
        $subtree = $subgraph->findSubtree($node, FindSubtreeFilter::create(
            nodeTypes: '!Neos.Neos:Document',
            maximumLevels: self::MAX_LEVELS,
        ));
        if ($subtree === null) {
            $io->writeError('Node not found in this subgraph.');
            return null;
        }

        $navigableNodes = [];
        $lines = $this->renderSubtree($subtree, '', true, $navigableNodes);

        $io->writeLine('');
        foreach ($lines as $line) {
            $io->writeLine($line);
        }

        if ($navigableNodes === []) {
            return null;
        }

        $choices = ['_stay' => '(stay here)'];
        foreach ($navigableNodes as $id => $label) {
            $choices[$id] = $label;
        }

        $selected = $io->choose('Navigate to node', $choices);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeInfo(sprintf('✔ Node set to: %s', $selected));
        return $context->with('node', NodeAggregateId::fromString($selected));
    }

    /**
     * @param array<string, string> $navigableNodes
     * @return list<string>
     */
    private function renderSubtree(
        Subtree $subtree,
        string $prefix,
        bool $isLast,
        array &$navigableNodes,
    ): array {
        $node = $subtree->node;
        $id = $node->aggregateId->value;
        $name = $node->name?->value ?? '-';
        $type = $node->nodeTypeName->value;
        // Shorten type: "Vendor.Package:Content.Text" → "Content.Text"
        $shortType = str_contains($type, ':') ? substr($type, strrpos($type, ':') + 1) : $type;

        $connector = $subtree->level === 0 ? '' : ($isLast ? '└─ ' : '├─ ');
        $line = $prefix . $connector . sprintf('(%s) %s %s', $name, $id, $shortType);

        $lines = [$line];
        $navigableNodes[$id] = sprintf('%s %s', $name, $shortType);

        $children = iterator_to_array($subtree->children);
        $childPrefix = $subtree->level === 0 ? '' : $prefix . ($isLast ? '   ' : '│  ');
        $count = count($children);
        foreach ($children as $i => $child) {
            $childLines = $this->renderSubtree($child, $childPrefix, $i === $count - 1, $navigableNodes);
            array_push($lines, ...$childLines);
        }

        return $lines;
    }
}
