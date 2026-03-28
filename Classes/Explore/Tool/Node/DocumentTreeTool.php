<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;

/**
 * @internal Renders a document subtree as an indented tree with node labels (like the Neos UI tree).
 *
 * When no node is selected, auto-detects the site root via Neos.Neos:Sites and shows the full document tree.
 * When a node is selected, shows the subtree rooted at that node.
 *
 * @see ContentSubgraphInterface::findSubtree() for the underlying tree lookup.
 * @see DocumentUriPathFinder for optional URI path enrichment.
 * @see NodeLabelGeneratorInterface for node label resolution from NodeTypes.yaml.
 */
#[ToolMeta(shortName: 'nDocTree', group: 'Nodes')]
final class DocumentTreeTool implements ToolInterface
{
    private const MAX_LEVELS = 4;

    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Document tree';
    }

    public function execute(
        ToolIOInterface          $io,
        ToolContext              $context,
        ContentSubgraphInterface $subgraph,
        ContentRepository        $cr,
        DimensionSpacePoint      $dsp,
        ?NodeAggregateId         $node = null,
    ): ?ToolContext
    {
        $entryNodeId = $node ?? $this->findSiteNodeId($subgraph, $io);
        if ($entryNodeId === null) {
            return null;
        }

        $filter = FindSubtreeFilter::create(
            nodeTypes: 'Neos.Neos:Document',
            maximumLevels: self::MAX_LEVELS,
        );
        $subtree = $subgraph->findSubtree($entryNodeId, $filter);
        if ($subtree === null) {
            $io->writeError('Node not found in this subgraph.');
            return null;
        }

        $uriPathFinder = $this->resolveUriPathFinder($cr);

        /** @var array<string, array<string>> $tableRows uuid => [label, uriPath, type, nodeName] */
        $tableRows = ['_stay' => ['(stay here)', '', '', '']];
        $this->renderSubtree($subtree, $uriPathFinder, $dsp, '', true, $tableRows);

        $selected = $io->chooseFromTable('Navigate to node', ['Label', 'URI Path', 'Type', 'Node Name'], $tableRows);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeInfo(sprintf('✔ Node set to: %s', $selected));
        return $context->with('node', NodeAggregateId::fromString($selected));
    }

    private function findSiteNodeId(ContentSubgraphInterface $subgraph, ToolIOInterface $io): ?NodeAggregateId
    {
        $sitesRoot = $subgraph->findRootNodeByType(NodeTypeName::fromString('Neos.Neos:Sites'));
        if ($sitesRoot === null) {
            $io->writeError('Neos.Neos:Sites root node not found.');
            return null;
        }

        $siteNodes = $subgraph->findChildNodes($sitesRoot->aggregateId, FindChildNodesFilter::create());
        $sites = iterator_to_array($siteNodes);

        if ($sites === []) {
            $io->writeError('No site nodes found under Neos.Neos:Sites.');
            return null;
        }

        if (count($sites) === 1) {
            return $sites[0]->aggregateId;
        }

        $rows = [];
        foreach ($sites as $site) {
            $id = $site->aggregateId->value;
            $rows[$id] = [sprintf('%s (%s)', $site->name?->value ?? '-', $site->nodeTypeName->value)];
        }

        $selected = $io->chooseFromTable('Multiple sites found — choose one', ['Site'], $rows);
        return NodeAggregateId::fromString($selected);
    }

    /** @param array<string, array<string>> $tableRows uuid => row columns */
    private function renderSubtree(
        Subtree                $subtree,
        ?DocumentUriPathFinder $uriPathFinder,
        DimensionSpacePoint    $dsp,
        string                 $prefix,
        bool                   $isLast,
        array                  &$tableRows,
    ): void
    {
        $node = $subtree->node;
        $id = $node->aggregateId->value;

        $uriPath = $this->resolveUriPath($uriPathFinder, $node->aggregateId, $dsp);
        $connector = $subtree->level === 0 ? '' : ($isLast ? '└─ ' : '├─ ');

        $tableRows[$id] = [
            $prefix . $connector . $this->nodeLabel($node),
            $uriPath ?? '',
            $node->nodeTypeName->value,
            $node->name?->value ?? '-',
        ];

        $children = iterator_to_array($subtree->children);
        $childPrefix = $subtree->level === 0 ? '' : $prefix . ($isLast ? '   ' : '│  ');
        $count = count($children);
        foreach ($children as $i => $child) {
            $this->renderSubtree($child, $uriPathFinder, $dsp, $childPrefix, $i === $count - 1, $tableRows);
        }
    }

    private function resolveUriPathFinder(ContentRepository $cr): ?DocumentUriPathFinder
    {
        try {
            return $cr->projectionState(DocumentUriPathFinder::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveUriPath(
        ?DocumentUriPathFinder $finder,
        NodeAggregateId        $nodeId,
        DimensionSpacePoint    $dsp,
    ): ?string
    {
        if ($finder === null) {
            return null;
        }
        try {
            $docInfo = $finder->getByIdAndDimensionSpacePointHash($nodeId, $dsp->hash);
            return $docInfo->hasUriPath() ? '/' . $docInfo->getUriPath() : '/';
        } catch (\Throwable) {
            return null;
        }
    }

    private function nodeLabel(Node $node): string
    {
        try {
            return $this->nodeLabelGenerator->getLabel($node);
        } catch (\Throwable) {
            return $node->nodeTypeName->value . ' (' . ($node->name?->value ?? '-') . ')';
        }
    }
}
