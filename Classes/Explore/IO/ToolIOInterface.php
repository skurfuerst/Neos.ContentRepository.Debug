<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\IO;

/**
 * @api Implement this to add a new transport (CLI, web, MCP) — all tools communicate exclusively through this interface.
 */
interface ToolIOInterface
{
    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function writeTable(array $headers, array $rows): void;

    /** @param array<string, string> $pairs */
    public function writeKeyValue(array $pairs): void;

    public function writeLine(string $text = ''): void;

    public function writeError(string $message): void;

    /**
     * Free-text prompt with optional live autocomplete.
     *
     * @param callable(string $partial): string[] $autocomplete
     */
    public function ask(string $question, ?callable $autocomplete = null): string;

    /**
     * Present a numbered list of choices; returns the selected key.
     *
     * @param array<string, string> $choices key => label
     */
    public function choose(string $question, array $choices): string;
}
