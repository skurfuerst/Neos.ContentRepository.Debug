<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;

/**
 * @internal Shows the URI path for the current node via the Neos routing projection.
 *
 * @see DocumentUriPathFinder::getByIdAndDimensionSpacePointHash() for the underlying lookup.
 */
#[ToolMeta(shortName: 'uriPath', group: 'Other')]
final class NodeRoutingTool implements ToolInterface
{
    public function __construct(
        private readonly ContentRepository $cr,
        private readonly NodeAggregateId $node,
        private readonly DimensionSpacePoint $dsp,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: URI path';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        try {
            $finder = $this->cr->projectionState(DocumentUriPathFinder::class);
        } catch (\Throwable) {
            $io->writeError('DocumentUriPathFinder projection not available in this content repository.');
            return null;
        }

        try {
            $docInfo = $finder->getByIdAndDimensionSpacePointHash($this->node, $this->dsp->hash);
        } catch (\Throwable) {
            $io->writeError(sprintf('No routing information found for node "%s" in this dimension.', $this->node->value));
            return null;
        }

        $io->writeKeyValue([
            'URI Path' => $docInfo->hasUriPath() ? '/' . $docInfo->getUriPath() : '(none)',
            'Node Type' => $docInfo->getNodeTypeName()->value,
            'Site' => $docInfo->getSiteNodeName()->value,
            'Disabled' => $docInfo->isDisabled() ? 'yes (level ' . $docInfo->getDisableLevel() . ')' : 'no',
            'Shortcut' => $docInfo->isShortcut() ? $docInfo->getShortcutTarget() : '(none)',
        ]);

        return null;
    }
}
