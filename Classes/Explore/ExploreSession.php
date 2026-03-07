<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;

/**
 * @internal Transport-agnostic session loop — construct with a {@see ToolDispatcher}, then call run()
 *           with a {@see ToolIOInterface} implementation to drive a session over any transport.
 */
final class ExploreSession
{
    private static ?ToolContext $exitSentinel = null;

    /**
     * Sentinel value: return this from a tool's execute() to end the session.
     * Recognised by identity (===) in {@see ExploreSession::run}.
     *
     * Usage in an exit tool:
     * ```php
     * public function execute(ToolIOInterface $io): ?ToolContext {
     *     return ExploreSession::exit();
     * }
     * ```
     */
    public static function exit(): ToolContext
    {
        return self::$exitSentinel ??= ToolContext::empty();
    }

    /**
     * @param ?\Closure(ToolContext, ToolIOInterface): void $contextRenderer Called before each menu to display
     *        the current session state. Kept optional so tests and minimal setups can omit it.
     */
    public function __construct(
        private readonly ToolDispatcher $dispatcher,
        private readonly ?\Closure $contextRenderer = null,
    ) {}

    public function run(ToolContext $context, ToolIOInterface $io): void
    {
        while (true) {
            if ($this->contextRenderer !== null) {
                ($this->contextRenderer)($context, $io);
            }

            $available = $this->dispatcher->availableTools($context);

            $choices = [];
            foreach ($available as $i => $tool) {
                $choices[(string)$i] = $tool->getMenuLabel($context);
            }

            $selected = $io->choose('Choose a tool', $choices);
            $tool = $available[(int)$selected];

            $result = $this->dispatcher->execute($tool, $context, $io);

            if ($result === self::exit()) {
                return;
            }

            if ($result !== null) {
                $context = $result;
            }
        }
    }
}
