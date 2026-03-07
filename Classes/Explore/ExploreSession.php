<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Transport-agnostic session loop — construct with a {@see ToolDispatcher}, then call run()
 *           with a {@see ToolIOInterface} implementation to drive a session over any transport.
 * @Flow\Proxy(false)
 */

final class ExploreSession
{
    /**
     * Sentinel value: return this from a tool's execute() to end the session.
     *
     * Recognised by identity (===) in {@see ExploreSession::run}.
     * Initialised at file load time below the class definition.
     *
     * Usage in an exit tool:
     * ```php
     * public function execute(ToolIOInterface $io): ?ToolContext {
     *     return ExploreSession::$EXIT;
     * }
     * ```
     */
    public static ToolContext $EXIT;

    public function __construct(private readonly ToolDispatcher $dispatcher) {}

    public function run(ToolContext $context, ToolIOInterface $io): void
    {
        while (true) {
            $available = $this->dispatcher->availableTools($context);

            $choices = [];
            foreach ($available as $i => $tool) {
                $choices[(string)$i] = $tool->getMenuLabel($context);
            }

            $selected = $io->choose('Choose a tool', $choices);
            $tool = $available[(int)$selected];

            $result = $this->dispatcher->execute($tool, $context, $io);

            if ($result === self::$EXIT) {
                return;
            }

            if ($result !== null) {
                $context = $result;
            }
        }
    }
}

// Initialised here so the sentinel is a stable object identity across all usages.
ExploreSession::$EXIT = ToolContext::empty();
