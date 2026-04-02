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
    public function __construct(
        private readonly ToolContext $context,
        private readonly ContentGraphInterface $contentGraph,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Explore node types';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $nodeTypeNames = $this->contentGraph->findUsedNodeTypeNames();

        $typeRows = [];
        foreach ($nodeTypeNames as $nodeTypeName) {
            $typeRows[$nodeTypeName->value] = [$nodeTypeName->value];
        }

        if ($typeRows === []) {
            $io->writeLine('No node types in use.');
            return null;
        }

        ksort($typeRows);
        $selectedType = $io->chooseFromTable('Choose node type', ['Node Type'], $typeRows);

        $aggregates = $this->contentGraph->findNodeAggregatesByType(NodeTypeName::fromString($selectedType));

        $tableRows = ['_stay' => ['(stay here)', '', '']];
        foreach ($aggregates as $aggregate) {
            $id = $aggregate->nodeAggregateId->value;
            $tableRows[$id] = [$id, $aggregate->nodeName?->value ?? '–', $aggregate->classification->value];
        }

        if (count($tableRows) === 1) {
            $io->writeLine('No aggregates found for this type.');
            return null;
        }

        $selected = $io->chooseFromTable('Navigate to node', ['ID', 'Name', 'Classification'], $tableRows);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeInfo(sprintf('✔ Node set to: %s', $selected));
        return $this->context->with('node', NodeAggregateId::fromString($selected));
    }
}
