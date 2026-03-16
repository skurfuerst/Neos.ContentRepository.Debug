<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\MCP;

/**
 * @internal Thrown by {@see McpToolIO} when a tool requires user input that wasn't pre-supplied — the MCP client
 *           should re-invoke the tool with the answer included.
 */
final class McpInteractionRequiredException extends \RuntimeException
{
    /**
     * @param 'ask'|'choose' $interactionType
     * @param array<string, string> $choices Only populated for 'choose' interactions
     */
    public function __construct(
        public readonly string $interactionType,
        public readonly string $question,
        public readonly array $choices,
        public readonly int $ordinal,
    ) {
        parent::__construct(sprintf('Interaction required (ordinal %d): %s', $ordinal, $question));
    }
}
