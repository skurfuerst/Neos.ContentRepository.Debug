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
     * @param ?\Closure(ToolContext): string $resumeCommandBuilder Builds the resume command string shown
     *        dimmed inside the tool-selector widget. Omit in tests and minimal setups.
     */
    public function __construct(
        private readonly ToolDispatcher $dispatcher,
        private readonly ?\Closure $resumeCommandBuilder = null,
    ) {}

    public function run(ToolContext $context, ToolIOInterface $io): void
    {
        // Bootstrap: notify tools before the first menu render
        $this->dispatcher->notifyContextChange(ToolContext::empty(), $context, $io);

        /** @var array<string, true>|null $baselineToolSet null on first iteration (don't mark anything as new) */
        $baselineToolSet = null;
        $contextJustChanged = false;

        while (true) {
            $contextDisplay = $this->resumeCommandBuilder !== null
                ? ($this->resumeCommandBuilder)($context)
                : '';
            $menu = $this->dispatcher->buildMenu($context, $contextDisplay);
            $available = $menu->available();

            // Auto-run tools on every context change (e.g. NodeInfoTool re-runs when navigating nodes)
            if ($contextJustChanged) {
                foreach ($menu->availableAutoRun() as $autoItem) {
                    $io->writeLine('');
                    $io->writeInfo('--- ' . $autoItem->label . ' ---');
                    $this->dispatcher->execute($autoItem->toolClass, $context, $io);
                }
            }

            $shortName = $io->chooseFromMenu($menu);
            $item = $menu->findByShortName($shortName);
            // findByShortName is guaranteed non-null here: chooseFromMenu only returns valid, available short names
            assert($item !== null);

            $io->writeLine('');
            $io->writeInfo('# ' . $shortName . ': ' . $item->label);

            $result = $this->dispatcher->execute($item->toolClass, $context, $io);

            if ($result === self::exit()) {
                return;
            }

            if ($result !== null) {
                $oldContext = $context;
                $context = $result;
                // Notify tools immediately after context update (before next menu render)
                $this->dispatcher->notifyContextChange($oldContext, $context, $io);
                $contextJustChanged = true;
                $baselineToolSet = [];
                foreach ($available as $availItem) {
                    $baselineToolSet[$availItem->toolClass] = true;
                }
            } else {
                $contextJustChanged = false;
                if ($baselineToolSet === null) {
                    $baselineToolSet = [];
                    foreach ($available as $availItem) {
                        $baselineToolSet[$availItem->toolClass] = true;
                    }
                }
            }
        }
    }
}
