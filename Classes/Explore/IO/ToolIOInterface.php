<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\IO;

use Neos\ContentRepository\Debug\Explore\ToolMenu;

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

    /** Display an informational heading — rendered green in CLI, semantic in MCP. */
    public function writeInfo(string $message): void;

    /** Display an emphasized note/section label — rendered yellow in CLI, semantic in MCP. */
    public function writeNote(string $message): void;

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

    /**
     * Present a numbered list of choices; returns the selected keys.
     *
     * @param array<string, string> $choices  key => label
     * @param list<string>          $default  keys pre-selected when the prompt opens
     * @return list<string>
     */
    public function chooseMultiple(string $question, array $choices, array $default = []): array;

    /**
     * Rich tool-selection prompt: renders a grouped display of all tools (available + unavailable)
     * and returns the {@see ToolMenuItem::$shortName} of the selected tool.
     *
     * The implementation is responsible for re-prompting when the user picks an unavailable tool,
     * and for showing what context is needed in that case.
     */
    public function chooseFromMenu(ToolMenu $menu): string;
}
