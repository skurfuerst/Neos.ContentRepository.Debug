<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\IO;
use Neos\Flow\Cli\ConsoleOutput;

/**
 * @internal Adapts Flow's {@see ConsoleOutput} to the {@see ToolIOInterface} contract for interactive CLI sessions.
 */

final class CliToolIO implements ToolIOInterface
{
    public function __construct(private readonly ConsoleOutput $console) {}

    public function writeTable(array $headers, array $rows): void
    {
        $this->console->outputTable($rows, $headers);
    }

    public function writeKeyValue(array $pairs): void
    {
        $rows = [];
        foreach ($pairs as $key => $value) {
            $rows[] = ["<b>{$key}</b>", $value];
        }
        $this->console->outputTable($rows);
    }

    public function writeLine(string $text = ''): void
    {
        $this->console->outputLine($text);
    }

    public function writeError(string $message): void
    {
        $this->console->outputLine('<error>' . $message . '</error>');
    }

    public function ask(string $question, ?callable $autocomplete = null): string
    {
        return (string)$this->console->ask($question . ' ');
    }

    public function choose(string $question, array $choices): string
    {
        // Flow's select() returns the value (label), not the key.
        // We pass the original keys so Symfony shows [key] label, and returns the label.
        $selected = $this->console->select($question, $choices);
        $flipped = array_flip($choices);
        return (string)$flipped[$selected];
    }
}
