<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Presents the covered dimension space points for the current node and sets the chosen DSP in context.
 *
 * @see ContentGraphInterface::findNodeAggregateById() to look up dimension coverage.
 */
final class ChooseDimensionTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Choose dimension';
    }

    public function execute(ToolIOInterface $io, ToolContext $context, ContentGraphInterface $contentGraph, NodeAggregateId $node): ?ToolContext
    {
        $aggregate = $contentGraph->findNodeAggregateById($node);
        if ($aggregate === null) {
            $io->writeError(sprintf('Node aggregate "%s" not found.', $node->value));
            return null;
        }

        $choices = [];
        foreach ($aggregate->coveredDimensionSpacePoints as $dsp) {
            $choices[$dsp->toJson()] = $dsp->toJson();
        }

        if ($choices === []) {
            $io->writeLine('No dimension space points available.');
            return null;
        }

        $selected = $io->choose('Choose dimension space point', $choices);

        return $context->withFromString('dsp', $selected);
    }
}
