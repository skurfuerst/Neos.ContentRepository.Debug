<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;

/**
 * @internal Prompts for a node UUID and sets the 'node' context value using the registry's fromString callback.
 */

final class SetNodeByUuidTool implements ToolInterface
{
    public function __construct(private readonly ToolContextRegistry $registry) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Set node by UUID';
    }

    public function execute(ToolIOInterface $io, ToolContext $context): ?ToolContext
    {
        $descriptor = $this->registry->getByName('node');
        if ($descriptor === null) {
            $io->writeError('Context type "node" is not registered.');
            return null;
        }
        $uuid = $io->ask('Enter node UUID:');
        return $context->with('node', $descriptor->fromString($uuid));
    }
}
