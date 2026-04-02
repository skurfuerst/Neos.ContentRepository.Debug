<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;

/**
 * @internal Renders the full content tree (all node types) under the current node with node labels —
 *           shows the page structure including content collections, content nodes, etc.
 *
 * @see ContentSubgraphInterface::findSubtree() for the underlying tree lookup.
 * @see NodeLabelGeneratorInterface for node label resolution from NodeTypes.yaml.
 */
#[ToolMeta(shortName: 'nContentTree', group: 'Nodes')]
final class ContentTreeTool implements ToolInterface
{
    private const MAX_LEVELS = 5;

    public function __construct(
        private readonly NodeLabelGeneratorInterface $nodeLabelGenerator,
        private readonly ToolContext $context,
        private readonly ContentSubgraphInterface $subgraph,
        private readonly NodeAggregateId $node,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: content tree';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        // Show content structure only — exclude document descendants (subpages)
        $subtree = $this->subgraph->findSubtree($this->node, FindSubtreeFilter::create(
            nodeTypes: '!Neos.Neos:Document',
            maximumLevels: self::MAX_LEVELS,
        ));
        if ($subtree === null) {
            $io->writeError('Node not found in this subgraph.');
            return null;
        }

        /** @var array<string, array<string>> $tableRows uuid => [label, type, nodeName] */
        $tableRows = ['_stay' => ['(stay here)', '', '']];
        $this->renderSubtree($subtree, '', true, $tableRows);

        $selected = $io->chooseFromTable('Navigate to node', ['Label', 'Type', 'Node Name'], $tableRows);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeInfo(sprintf('✔ Node set to: %s', $selected));
        return $this->context->with('node', NodeAggregateId::fromString($selected));
    }

    /** @param array<string, array<string>> $tableRows id => row columns */
    private function renderSubtree(
        Subtree $subtree,
        string $prefix,
        bool $isLast,
        array &$tableRows,
    ): void {
        $node = $subtree->node;
        $id = $node->aggregateId->value;

        $connector = $subtree->level === 0 ? '' : ($isLast ? '└─ ' : '├─ ');
        $tableRows[$id] = [
            $prefix . $connector . $this->nodeLabel($node),
            $node->nodeTypeName->value,
            $node->name?->value ?? '-',
        ];

        $children = iterator_to_array($subtree->children);
        $childPrefix = $subtree->level === 0 ? '' : $prefix . ($isLast ? '   ' : '│  ');
        $count = count($children);
        foreach ($children as $i => $child) {
            $this->renderSubtree($child, $childPrefix, $i === $count - 1, $tableRows);
        }
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
