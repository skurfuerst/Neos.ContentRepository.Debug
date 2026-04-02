<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @api Implement this interface and add an execute(ToolIOInterface $io): ?ToolContext method to create
 *      a tool shown in the explore menu.
 *
 * ## Dependency injection via constructor
 *
 * All dependencies are injected through the constructor by {@see \Neos\ContentRepository\Debug\Explore\ToolBuilder}.
 * The constructor is reflected per-dispatch; each parameter is resolved in order:
 *
 *   1. {@see ToolContext} — the current session context (for returning updated context).
 *   2. Registered context types ({@see \Neos\ContentRepository\Debug\Explore\ToolContextRegistry}) —
 *      ContentRepositoryId, WorkspaceName, DimensionSpacePoint, NodeAggregateId. Required (non-nullable)
 *      params make the tool unavailable when the value is absent from the session context.
 *   3. Derived types — ContentRepository, ContentGraphInterface, ContentSubgraphInterface,
 *      EventStoreInterface, ContentRepositoryMaintainer. Resolved via registered closures; required
 *      params make the tool unavailable when their underlying context values are missing.
 *   4. Everything else — resolved via Flow's ObjectManagerInterface (singletons, services).
 *
 * ## execute() signature
 *
 * ```php
 * public function execute(ToolIOInterface $io): ?ToolContext
 * ```
 *
 * Return null to leave the context unchanged, or return a new {@see ToolContext} (built via
 * `$this->context->with(...)`) to update the session state. Return
 * {@see \Neos\ContentRepository\Debug\Explore\ExploreSession::exit()} to end the session.
 *
 * ## getMenuLabel() constraint
 *
 * getMenuLabel() MUST NOT access $this — only the $context parameter. The dispatcher calls it on
 * a shell instance created with newInstanceWithoutConstructor() for efficiency.
 *
 * ## Example
 *
 * ```php
 * #[ToolMeta(shortName: 'myTool', group: 'MyGroup')]
 * final class MyTool implements ToolInterface
 * {
 *     public function __construct(
 *         private readonly ToolContext $context,       // for updating context
 *         private readonly ContentRepository $cr,     // derived type — unavailable without CR in context
 *         private readonly NodeAggregateId $node,     // registered type — unavailable without node in context
 *         private readonly MyService $myService,      // ObjectManager-injected service
 *     ) {}
 *
 *     public function getMenuLabel(ToolContext $context): string
 *     {
 *         return 'My tool label';
 *     }
 *
 *     public function execute(ToolIOInterface $io): ?ToolContext
 *     {
 *         // use $this->cr, $this->node, $this->myService
 *         return null;
 *     }
 * }
 * ```
 */
interface ToolInterface
{
    /**
     * Label shown in the numbered menu. May inspect $context to produce a context-sensitive label.
     * MUST NOT access $this — only $context.
     */
    public function getMenuLabel(ToolContext $context): string;

    public function execute(ToolIOInterface $io): ?ToolContext;
}
