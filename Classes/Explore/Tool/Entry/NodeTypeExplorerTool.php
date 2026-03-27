<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Browse node types in use, list aggregates of a chosen type, and navigate to one.
 *
 * @see ContentGraphInterface::findUsedNodeTypeNames() for discovering types.
 * @see ContentGraphInterface::findNodeAggregatesByType() for listing aggregates.
 */
#[ToolMeta(shortName: 'types', group: 'Other')]
final class NodeTypeExplorerTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Explore node types';
    }

    public function execute(ToolIOInterface $io, ToolContext $context, ContentGraphInterface $contentGraph): ?ToolContext
    {
        $nodeTypeNames = $contentGraph->findUsedNodeTypeNames();

        $typeChoices = [];
        foreach ($nodeTypeNames as $nodeTypeName) {
            $typeChoices[$nodeTypeName->value] = $nodeTypeName->value;
        }

        if ($typeChoices === []) {
            $io->writeLine('No node types in use.');
            return null;
        }

        ksort($typeChoices);
        $selectedType = $io->choose('Choose node type', $typeChoices);

        $aggregates = $contentGraph->findNodeAggregatesByType(NodeTypeName::fromString($selectedType));

        $choices = ['_stay' => '(stay here)'];
        $rows = [];
        foreach ($aggregates as $aggregate) {
            $id = $aggregate->nodeAggregateId->value;
            $choices[$id] = sprintf('%s — %s', $id, $aggregate->nodeName?->value ?? '–');
            $rows[] = [$id, $aggregate->nodeName?->value ?? '–', $aggregate->classification->value];
        }

        if ($rows === []) {
            $io->writeLine('No aggregates found for this type.');
            return null;
        }

        $io->writeTable(['ID', 'Name', 'Classification'], $rows);

        $selected = $io->choose('Navigate to node', $choices);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeInfo(sprintf('✔ Node set to: %s', $selected));
        return $context->with('node', NodeAggregateId::fromString($selected));
    }
}
