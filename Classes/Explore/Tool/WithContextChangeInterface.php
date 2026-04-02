<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Marker interface for tools that need to react to context changes and session bootstrap.
 *
 * Implement this alongside {@see ToolInterface} to receive {@see ToolDispatcher::notifyContextChange()}
 * calls on every context change *and* once at bootstrap (old context = {@see \Neos\ContentRepository\Debug\Explore\ToolContext::empty()}).
 *
 * The `onContextChange()` method is discovered by reflection — same injection rules as `execute()`:
 *   - First `ToolContext` param   → receives the old context
 *   - Second `ToolContext` param  → receives the new context
 *   - `ToolIOInterface`           → the active I/O channel
 *   - Registered context types    → resolved from the **new** context
 *   - Derived types               → resolved from the **new** context
 *
 * If a required (non-nullable, non-optional) parameter cannot be resolved from the new context, the
 * callback is **silently skipped** — consistent with how unavailable tools are handled.
 *
 * The dispatcher calls `onContextChange` in two passes to guarantee that setup (e.g. dynamic CR
 * registration) happens before tools that depend on derived services:
 *   - Pass 1: tools whose `onContextChange` params include **no derived types**
 *   - Pass 2: tools whose `onContextChange` params include at least one derived type
 *
 * Return value is ignored (`void` recommended).
 *
 * @see \Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface for a related mechanism that
 *      fires `execute()` automatically when the tool becomes newly available in the menu loop.
 */
interface WithContextChangeInterface
{
    public function onContextChange(ToolContext $old, ToolContext $new, ToolIOInterface $io): void;
}
