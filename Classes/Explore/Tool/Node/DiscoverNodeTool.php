<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Searches all workspaces to find where a node aggregate exists — shows node type, covered DSPs per workspace.
 *
 * Useful right after entering a UUID to understand where the node lives before choosing a workspace/DSP.
 *
 * @see ContentRepository::findWorkspaces() to iterate workspaces.
 */
final class DiscoverNodeTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: discover (find in workspaces)';
    }

    public function execute(ToolIOInterface $io, ContentRepository $cr, NodeAggregateId $node): ?ToolContext
    {
        $workspaces = $cr->findWorkspaces();
        $found = false;

        $rows = [];
        foreach ($workspaces as $workspace) {
            $contentGraph = $cr->getContentGraph($workspace->workspaceName);
            $aggregate = $contentGraph->findNodeAggregateById($node);
            if ($aggregate === null) {
                continue;
            }
            $found = true;
            $coveredDsps = array_map(
                static fn($dsp) => $dsp->toJson(),
                iterator_to_array($aggregate->coveredDimensionSpacePoints),
            );
            $rows[] = [
                $workspace->workspaceName->value,
                $aggregate->nodeTypeName->value,
                implode(', ', $coveredDsps),
            ];
        }

        if (!$found) {
            $io->writeError(sprintf('Node aggregate "%s" not found in any workspace.', $node->value));
            return null;
        }

        $io->writeTable(['Workspace', 'Node Type', 'Covered DSPs'], $rows);

        return null;
    }
}
