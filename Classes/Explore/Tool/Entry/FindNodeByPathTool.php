<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;

/**
 * @internal Resolves a URL path to a node via the Neos routing projection and sets it in context.
 *
 * Uses {@see SiteRepository} to auto-detect the site (asks user if ambiguous).
 * @see DocumentUriPathFinder::getEnabledBySiteNodeNameUriPathAndDimensionSpacePointHash() for the lookup.
 */
#[ToolMeta(shortName: 'path', group: 'Other')]
final class FindNodeByPathTool implements ToolInterface
{
    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Find node by URL path';
    }

    public function execute(ToolIOInterface $io, ToolContext $context, ContentRepository $cr, DimensionSpacePoint $dsp): ?ToolContext
    {
        try {
            $finder = $cr->projectionState(DocumentUriPathFinder::class);
        } catch (\Throwable) {
            $io->writeError('DocumentUriPathFinder projection not available in this content repository.');
            return null;
        }

        // Resolve site node name — auto-detect or ask
        $siteNodeName = $this->resolveSiteNodeName($io);
        if ($siteNodeName === null) {
            return null;
        }

        $uriPath = $io->ask('Enter URL path (e.g. /en/about):');
        $uriPath = ltrim($uriPath, '/');

        try {
            $docInfo = $finder->getEnabledBySiteNodeNameUriPathAndDimensionSpacePointHash(
                $siteNodeName,
                $uriPath,
                $dsp->hash,
            );
        } catch (\Throwable) {
            $io->writeError(sprintf('No node found for path "/%s" in this dimension.', $uriPath));
            return null;
        }

        $io->writeKeyValue([
            'URI Path' => '/' . $docInfo->getUriPath(),
            'Node ID' => $docInfo->getNodeAggregateId()->value,
            'Node Type' => $docInfo->getNodeTypeName()->value,
        ]);

        $io->writeInfo(sprintf('✔ Node set to: %s', $docInfo->getNodeAggregateId()->value));
        return $context->with('node', $docInfo->getNodeAggregateId());
    }

    private function resolveSiteNodeName(ToolIOInterface $io): ?SiteNodeName
    {
        $sites = $this->siteRepository->findOnline();
        $siteList = iterator_to_array($sites);

        if ($siteList === []) {
            $io->writeError('No online sites found.');
            return null;
        }

        if (count($siteList) === 1) {
            return $siteList[0]->getNodeName();
        }

        $choices = [];
        foreach ($siteList as $site) {
            $choices[$site->getNodeName()->value] = $site->getName() . ' (' . $site->getNodeName()->value . ')';
        }
        $selected = $io->choose('Multiple sites found — choose one', $choices);
        return SiteNodeName::fromString($selected);
    }
}
