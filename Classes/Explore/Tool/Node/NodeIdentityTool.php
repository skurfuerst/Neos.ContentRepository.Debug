<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Displays node aggregate identity: ID, node type, classification, parent aggregates.
 *
 * @see ContentGraphInterface::findNodeAggregateById() for the underlying lookup.
 */
final class NodeIdentityTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: identity';
    }

    public function execute(ToolIOInterface $io, ContentGraphInterface $contentGraph, NodeAggregateId $node): ?ToolContext
    {
        $aggregate = $contentGraph->findNodeAggregateById($node);
        if ($aggregate === null) {
            $io->writeError(sprintf('Node aggregate "%s" not found.', $node->value));
            return null;
        }

        $parentIds = $contentGraph->findParentNodeAggregates($node);
        $parentIdStrings = array_map(
            static fn($parent) => $parent->nodeAggregateId->value,
            iterator_to_array($parentIds),
        );

        $io->writeKeyValue([
            'ID' => $aggregate->nodeAggregateId->value,
            'Type' => $aggregate->nodeTypeName->value,
            'Name' => $aggregate->nodeName?->value ?? '(none)',
            'Classification' => $aggregate->classification->value,
            'Parents' => $parentIdStrings !== [] ? implode(', ', $parentIdStrings) : '(root)',
        ]);

        return null;
    }
}
