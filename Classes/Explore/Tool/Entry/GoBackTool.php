<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Removes one named context slot, effectively stepping back one navigation level.
 *           The slot to remove is configured at construction time.
 */
final class GoBackTool implements ToolInterface
{
    public function __construct(private readonly string $contextNameToRemove) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return "Go back (remove {$this->contextNameToRemove})";
    }

    public function execute(ToolIOInterface $io, ToolContext $context): ?ToolContext
    {
        return $context->without($this->contextNameToRemove);
    }
}
