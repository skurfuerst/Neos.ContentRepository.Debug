<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Prompts for a node UUID and sets the 'node' context value via {@see ToolContext::withFromString}.
 */
#[ToolMeta(shortName: 'nId', group: 'Nodes')]
final class SetNodeByUuidTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Set node by UUID';
    }

    public function execute(ToolIOInterface $io, ToolContext $context): ?ToolContext
    {
        $uuid = trim($io->ask('Enter node UUID:'));
        if ($uuid === '') {
            return null;
        }
        $io->writeInfo(sprintf('✔ Node set to: %s', $uuid));
        return $context->withFromString('node', $uuid);
    }
}
