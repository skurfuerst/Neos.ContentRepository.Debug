<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Navigation;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Navigates to the parent node in the current subgraph, replacing the node context value.
 *
 * @see ContentSubgraphInterface::findParentNode() for the underlying lookup.
 */
final class GoToParentNodeTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Go to parent node';
    }

    public function execute(ToolIOInterface $io, ToolContext $context, ContentSubgraphInterface $subgraph, NodeAggregateId $node): ?ToolContext
    {
        $parent = $subgraph->findParentNode($node);
        if ($parent === null) {
            $io->writeError('Current node has no parent (root node).');
            return null;
        }

        $io->writeLine(sprintf('→ %s (%s)', $parent->aggregateId->value, $parent->nodeTypeName->value));

        return $context->with('node', $parent->aggregateId);
    }
}
