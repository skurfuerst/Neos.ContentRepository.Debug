<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool;

use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @api Implement this interface and add an execute() method to create a tool shown in the explore menu.
 *
 * The execute() method is discovered by reflection in {@see \Neos\ContentRepository\Debug\Explore\ToolDispatcher}.
 * Its parameters determine availability and are resolved automatically:
 * - {@see \Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface} — always injected
 * - Any type registered in {@see \Neos\ContentRepository\Debug\Explore\ToolContextRegistry} — injected from context;
 *   required (non-nullable) params make the tool unavailable when the value is absent.
 *
 * Return null to leave the context unchanged, or return a new {@see ToolContext} (built via $context->with(...))
 * to update the session state. Return {@see \Neos\ContentRepository\Debug\Explore\ExploreSession::$EXIT} to end the session.
 *
 * Example signatures:
 * ```php
 * public function execute(ToolIOInterface $io): ?ToolContext  // always available
 * public function execute(ToolIOInterface $io, NodeAggregateId $node): ?ToolContext  // requires node in context
 * public function execute(ToolIOInterface $io, NodeAggregateId $node, ?DimensionSpacePoint $dsp = null): ?ToolContext
 * ```
 */
interface ToolInterface
{
    /**
     * Label shown in the numbered menu. May inspect $context to produce a context-sensitive label.
     */
    public function getMenuLabel(ToolContext $context): string;
}
