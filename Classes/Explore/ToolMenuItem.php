<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @internal One entry in a {@see ToolMenu}: carries the tool class, its short name, display label, group,
 *           and availability status for the current session context.
 */
#[Flow\Proxy(false)]
final class ToolMenuItem
{
    /**
     * @param class-string<ToolInterface> $toolClass   The tool class — build via {@see ToolBuilder::build()} to execute.
     * @param list<string> $missingContextTypes  Registered context-type names absent from the current context.
     * @param list<string> $requiredContextTypes All registered context-type names that the constructor requires
     *                                           (both present and missing), in parameter order.
     */
    public function __construct(
        public readonly string $shortName,
        public readonly string $label,
        public readonly string $group,
        public readonly bool $available,
        /** @var class-string<ToolInterface> */
        public readonly string $toolClass,
        public readonly array $missingContextTypes = [],
        public readonly array $requiredContextTypes = [],
    ) {}
}
