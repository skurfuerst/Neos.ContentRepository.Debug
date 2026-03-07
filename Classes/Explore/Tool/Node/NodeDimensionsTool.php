<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Displays dimension space point coverage for a node aggregate — origin vs covered DSPs.
 *
 * @see ContentGraphInterface::findNodeAggregateById() for the underlying lookup.
 */
final class NodeDimensionsTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: dimensions';
    }

    public function execute(ToolIOInterface $io, ContentGraphInterface $contentGraph, NodeAggregateId $node): ?ToolContext
    {
        $aggregate = $contentGraph->findNodeAggregateById($node);
        if ($aggregate === null) {
            $io->writeError(sprintf('Node aggregate "%s" not found.', $node->value));
            return null;
        }

        $rows = [];
        foreach ($aggregate->occupiedDimensionSpacePoints as $origin) {
            $coverage = $aggregate->getCoverageByOccupant($origin);
            $coveredPoints = implode(', ', array_map(static fn($dsp) => $dsp->toJson(), iterator_to_array($coverage)));
            $rows[] = [$origin->toJson(), $coveredPoints];
        }

        $io->writeTable(['Origin DSP', 'Covered DSPs'], $rows);

        return null;
    }
}
