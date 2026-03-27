<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Sets the dimension space point in context. When a node is selected, offers its covered DSPs;
 *           otherwise lists all allowed DSPs from the variation graph.
 *
 * @see ContentRepository::getVariationGraph() for discovering all allowed DSPs.
 * @see ContentGraphInterface::findNodeAggregateById() for node-specific dimension coverage.
 */
#[ToolMeta(shortName: 'dsp', group: 'Dimensions')]
final class ChooseDimensionTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Set dimension';
    }

    public function execute(
        ToolIOInterface $io,
        ToolContext $context,
        ContentRepository $cr,
        ?ContentGraphInterface $contentGraph = null,
        ?NodeAggregateId $node = null,
    ): ?ToolContext {
        $choices = $this->buildChoices($io, $cr, $contentGraph, $node);
        if ($choices === null) {
            return null;
        }

        $selected = $io->choose('Choose dimension space point', $choices);
        $io->writeInfo(sprintf('✔ Dimension set to: %s', $selected));

        return $context->withFromString('dsp', $selected);
    }

    /** @return array<string, string>|null */
    private function buildChoices(
        ToolIOInterface $io,
        ContentRepository $cr,
        ?ContentGraphInterface $contentGraph,
        ?NodeAggregateId $node,
    ): ?array {
        // When node + workspace are available, show only covered DSPs
        if ($contentGraph !== null && $node !== null) {
            $aggregate = $contentGraph->findNodeAggregateById($node);
            if ($aggregate !== null) {
                $choices = [];
                foreach ($aggregate->coveredDimensionSpacePoints as $dsp) {
                    $choices[$dsp->toJson()] = $dsp->toJson();
                }
                if ($choices !== []) {
                    return $choices;
                }
            }
        }

        // Fallback: all allowed DSPs from variation graph
        $allDsps = $cr->getVariationGraph()->getDimensionSpacePoints();
        $choices = [];
        foreach ($allDsps as $dsp) {
            /** @var DimensionSpacePoint $dsp */
            $choices[$dsp->toJson()] = $dsp->toJson();
        }

        if ($choices === []) {
            $io->writeLine('No dimension space points available.');
            return null;
        }

        return $choices;
    }
}
