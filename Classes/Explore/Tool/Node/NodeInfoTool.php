<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountReferencesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Consolidated node info: identity, dimension coverage, and workspace presence. Auto-runs when a node is set.
 *
 * Merges the functionality of the former NodeIdentityTool, NodeDimensionsTool, and DiscoverNodeTool
 * into a single overview shown immediately after a node is selected.
 *
 * @see ContentGraphInterface::findNodeAggregateById() for identity and dimension lookup.
 * @see ContentRepository::findWorkspaces() for workspace coverage.
 */
#[ToolMeta(shortName: 'n', group: 'Nodes')]
final class NodeInfoTool implements AutoRunToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node info';
    }

    public function execute(
        ToolIOInterface $io,
        ContentRepository $cr,
        NodeAggregateId $node,
        ?ContentGraphInterface $contentGraph = null,
        ?ContentSubgraphInterface $subgraph = null,
    ): ?ToolContext {
        // ── Identity + dimension coverage (requires a workspace in context) ───

        if ($contentGraph !== null) {
            $aggregate = $contentGraph->findNodeAggregateById($node);
            if ($aggregate === null) {
                $io->writeError(sprintf('Node aggregate "%s" not found.', $node->value));
                return null;
            }

            $parents = $contentGraph->findParentNodeAggregates($node);
            $parentInfo = [];
            foreach ($parents as $parent) {
                $parentInfo[] = sprintf('%s (%s)', $parent->nodeAggregateId->value, $parent->nodeTypeName->value);
            }

            $pairs = [
                'ID'             => $aggregate->nodeAggregateId->value,
                'Type'           => $aggregate->nodeTypeName->value,
                'Name'           => $aggregate->nodeName?->value ?? '(none)',
                'Classification' => $aggregate->classification->value,
                'Parents'        => $parentInfo !== [] ? implode(', ', $parentInfo) : '(root)',
            ];

            if ($subgraph !== null) {
                $foundNode = $subgraph->findNodeById($node);
                if ($foundNode !== null) {
                    $pairs['Properties']     = (string) iterator_count($foundNode->properties->serialized());
                    $pairs['Children']       = (string) $subgraph->countChildNodes($node, CountChildNodesFilter::create());
                    $pairs['References out'] = (string) $subgraph->countReferences($node, CountReferencesFilter::create());
                    $pairs['References in']  = (string) $subgraph->countBackReferences($node, CountBackReferencesFilter::create());

                    $ts = $foundNode->timestamps;
                    $pairs['Created']       = $ts->originalCreated->format('Y-m-d H:i:s');
                    $pairs['Last modified'] = $ts->originalLastModified?->format('Y-m-d H:i:s') ?? '(never)';
                }
            }

            $io->writeKeyValue($pairs);

            $dimRows = [];
            foreach ($aggregate->occupiedDimensionSpacePoints as $origin) {
                $coverage = $aggregate->getCoverageByOccupant($origin);
                $coveredPoints = implode(', ', array_map(static fn($dsp) => $dsp->toJson(), iterator_to_array($coverage)));
                $dimRows[] = [$origin->toJson(), $coveredPoints];
            }

            if ($dimRows !== []) {
                $io->writeLine('');
                $io->writeTable(['Origin DSP', 'Covered DSPs'], $dimRows);
            }
        }
        return null;
    }
}
