<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Prompts for a node UUID, creates the value object via the provided factory, and sets it in context.
 *           Concrete subclasses (or the DI-wired instance) supply the context name and factory.
 */
final class SetNodeByUuidTool implements ToolInterface
{
    /**
     * @param callable(string): object $nodeFromString
     */
    public function __construct(
        private readonly string $nodeContextName,
        private readonly mixed $nodeFromString,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Set node by UUID';
    }

    public function execute(ToolIOInterface $io, ToolContext $context): ?ToolContext
    {
        $uuid = $io->ask('Enter node UUID:');
        return $context->with($this->nodeContextName, ($this->nodeFromString)($uuid));
    }
}
