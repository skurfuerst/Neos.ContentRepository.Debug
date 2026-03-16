<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\MCP;

use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\FeatureSet\AbstractFeatureSet;

/**
 * @internal Registers the Explore MCP tools under the `debug_` prefix — only loaded when SJS.Flow.MCP is installed.
 */
#[Flow\Scope("singleton")]
final class DebugFeatureSet extends AbstractFeatureSet
{
    protected ?string $toolCallPrefix = 'debug';

    public function initialize(): void
    {
        $this->addTool(ExploreToolDispatcherTool::class);
    }
}
