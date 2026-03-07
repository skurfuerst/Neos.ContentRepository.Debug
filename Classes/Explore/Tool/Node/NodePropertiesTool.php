<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Displays all serialized properties of a node in a given dimension space point.
 *
 * @see ContentSubgraphInterface::findNodeById() for the underlying lookup.
 */
final class NodePropertiesTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: properties';
    }

    public function execute(ToolIOInterface $io, ContentSubgraphInterface $subgraph, NodeAggregateId $node): ?ToolContext
    {
        $foundNode = $subgraph->findNodeById($node);
        if ($foundNode === null) {
            $io->writeError(sprintf('Node "%s" not found in this subgraph.', $node->value));
            return null;
        }

        $pairs = [];
        foreach ($foundNode->properties->serialized() as $propertyName => $serializedValue) {
            $pairs[$propertyName] = json_encode($serializedValue->value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($pairs === []) {
            $io->writeLine('(no properties)');
        } else {
            $io->writeKeyValue($pairs);
        }

        return null;
    }
}
