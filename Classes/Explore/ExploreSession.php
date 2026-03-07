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
        /** @var array<class-string, true>|null $previousToolSet null on first iteration (don't mark anything as new) */
        $previousToolSet = null;

        while (true) {
            if ($this->contextRenderer !== null) {
                ($this->contextRenderer)($context, $io);
            }

            $available = $this->dispatcher->availableTools($context);

            $choices = [];
            foreach ($available as $i => $tool) {
                $label = $tool->getMenuLabel($context);
                if ($previousToolSet !== null && !isset($previousToolSet[$tool::class])) {
                    $label = '★ ' . $label;
                }
                $choices[(string)$i] = $label;
            }

            $previousToolSet = [];
            foreach ($available as $tool) {
                $previousToolSet[$tool::class] = true;
            }

            $selected = $io->choose('Choose a tool', $choices);
            $tool = $available[(int)$selected];

            $io->writeLine('');
            $io->writeLine('--- ' . $tool->getMenuLabel($context) . ' ---');

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
