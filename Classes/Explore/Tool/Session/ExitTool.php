<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Session;

use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Always-available tool that signals session exit via {@see ExploreSession::$EXIT}.
 */
final class ExitTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Exit';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        return ExploreSession::$EXIT;
    }
}
