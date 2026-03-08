<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;

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
        /** @var array<class-string, true>|null $baselineToolSet null on first iteration (don't mark anything as new) */
        $baselineToolSet = null;
        $contextJustChanged = false;

        while (true) {
            if ($this->contextRenderer !== null) {
                ($this->contextRenderer)($context, $io);
            }

            $available = $this->dispatcher->availableTools($context);

            // Auto-run tools on every context change (e.g. NodeIdentityTool re-runs when navigating nodes)
            if ($contextJustChanged) {
                foreach ($available as $tool) {
                    if ($tool instanceof AutoRunToolInterface) {
                        $io->writeLine('');
                        $io->writeLine('<info>--- ' . $tool->getMenuLabel($context) . ' ---</info>');
                        $this->dispatcher->execute($tool, $context, $io);
                    }
                }
            }

            $choices = [];
            foreach ($available as $i => $tool) {
                $label = $tool->getMenuLabel($context);
                if ($baselineToolSet !== null && !isset($baselineToolSet[$tool::class])) {
                    $label = '★ ' . $label;
                }
                $choices[(string)$i] = $label;
            }

            $selected = $io->choose('Choose a tool', $choices);
            $tool = $available[(int)$selected];

            $io->writeLine('');
            $io->writeLine('<info>--- ' . $tool->getMenuLabel($context) . ' ---</info>');

            $result = $this->dispatcher->execute($tool, $context, $io);

            if ($result === self::exit()) {
                return;
            }

            if ($result !== null) {
                $context = $result;
                $contextJustChanged = true;
                $baselineToolSet = [];
                foreach ($available as $t) {
                    $baselineToolSet[$t::class] = true;
                }
            } else {
                $contextJustChanged = false;
                if ($baselineToolSet === null) {
                    $baselineToolSet = [];
                    foreach ($available as $t) {
                        $baselineToolSet[$t::class] = true;
                    }
                }
            }
        }
    }
}
