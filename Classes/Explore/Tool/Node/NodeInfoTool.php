<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;

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
    public function __construct(
        private readonly ContentRepository $cr,
        private readonly NodeAggregateId $node,
        private readonly ?ContentGraphInterface $contentGraph = null,
        private readonly ?ContentSubgraphInterface $subgraph = null,
        private readonly ?DimensionSpacePoint $dsp = null,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node info';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        // ── Identity + dimension coverage (requires a workspace in context) ───

        if ($this->contentGraph !== null) {
            $aggregate = $this->contentGraph->findNodeAggregateById($this->node);
            if ($aggregate === null) {
                $io->writeError(sprintf('Node aggregate "%s" not found.', $this->node->value));
                return null;
            }

            $parents = $this->contentGraph->findParentNodeAggregates($this->node);
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

            if ($this->subgraph !== null) {
                $foundNode = $this->subgraph->findNodeById($this->node);
                if ($foundNode !== null) {
                    $pairs['Properties']     = (string) iterator_count($foundNode->properties->serialized());
                    $pairs['Children']       = (string) $this->subgraph->countChildNodes($this->node, CountChildNodesFilter::create());
                    $pairs['References out'] = (string) $this->subgraph->countReferences($this->node, CountReferencesFilter::create());
                    $pairs['References in']  = (string) $this->subgraph->countBackReferences($this->node, CountBackReferencesFilter::create());

                    $ts = $foundNode->timestamps;
                    $pairs['Created']       = $ts->originalCreated->format('Y-m-d H:i:s');
                    $pairs['Last modified'] = $ts->originalLastModified?->format('Y-m-d H:i:s') ?? '(never)';
                }
            }

            // ── Location context (URI path, enclosing document) ─────────────────
            if ($this->subgraph !== null && $this->dsp !== null) {
                $foundNode ??= $this->subgraph->findNodeById($this->node);
                if ($foundNode !== null) {
                    $this->addLocationContext($pairs, $this->cr, $this->subgraph, $foundNode, $this->dsp);
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

    /** @param array<string, string> $pairs */
    private function addLocationContext(
        array &$pairs,
        ContentRepository $cr,
        ContentSubgraphInterface $subgraph,
        Node $currentNode,
        DimensionSpacePoint $dsp,
    ): void {
        $isDocument = false;
        try {
            $isDocument = $cr->getNodeTypeManager()
                ->getNodeType($currentNode->nodeTypeName)
                ?->isOfType('Neos.Neos:Document') ?? false;
        } catch (\Throwable) {
            // NodeType not found — treat as content
        }

        if ($isDocument) {
            $pairs['URI Path'] = $this->resolveUriPath($cr, $currentNode->aggregateId, $dsp) ?? '(no routing)';
            return;
        }

        // Content node — find enclosing document
        $docNode = $subgraph->findClosestNode(
            $currentNode->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document'),
        );
        if ($docNode === null) {
            return;
        }

        $pairs['Enclosing Document'] = sprintf('%s (%s)', $docNode->aggregateId->value, $docNode->nodeTypeName->value);
        $pairs['Document URI'] = $this->resolveUriPath($cr, $docNode->aggregateId, $dsp) ?? '(no routing)';

        // Build path on page: walk from current node up to (not including) the document
        $pathParts = [];
        $walkNode = $currentNode;
        while ($walkNode !== null && !$walkNode->aggregateId->equals($docNode->aggregateId)) {
            if ($walkNode->name !== null) {
                $pathParts[] = $walkNode->name->value;
            }
            $walkNode = $subgraph->findParentNode($walkNode->aggregateId);
        }
        if ($pathParts !== []) {
            $pairs['Path on Page'] = implode(' → ', array_reverse($pathParts));
        }
    }

    private function resolveUriPath(ContentRepository $cr, NodeAggregateId $nodeId, DimensionSpacePoint $dsp): ?string
    {
        try {
            $finder = $cr->projectionState(DocumentUriPathFinder::class);
            $docInfo = $finder->getByIdAndDimensionSpacePointHash($nodeId, $dsp->hash);
            return $docInfo->hasUriPath() ? '/' . $docInfo->getUriPath() : '/';
        } catch (\Throwable) {
            return null;
        }
    }
}
