<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\MCP;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolMenu;
use Neos\ContentRepository\Debug\Explore\ToolMenuItem;

/**
 * @internal MCP transport for {@see ToolIOInterface} — buffers all output and consumes pre-supplied answers
 *           for stateless request/response MCP tool calls.
 *
 * When a tool calls {@see ask()} or {@see chooseFromTable()} and no pre-supplied answer remains,
 * a {@see McpInteractionRequiredException} is thrown so the MCP client can re-invoke with the answer.
 */
final class McpToolIO implements ToolIOInterface
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<array{headers: list<string>, rows: list<list<string>>}> */
    private array $tables = [];

    /** @var list<array<string, string>> */
    private array $keyValues = [];

    /** @var list<string> */
    private array $errors = [];

    private int $nextOrdinal = 0;

    /** @var list<string> */
    private array $answerQueue;

    /**
     * @param list<string> $answers Pre-supplied answers consumed in order by ask() and choose()
     */
    public function __construct(array $answers = [])
    {
        $this->answerQueue = $answers;
    }

    public function writeTable(array $headers, array $rows): void
    {
        $this->tables[] = ['headers' => $headers, 'rows' => $rows];
    }

    public function writeKeyValue(array $pairs): void
    {
        $this->keyValues[] = $pairs;
    }

    public function writeLine(string $text = ''): void
    {
        $this->lines[] = $text;
    }

    public function writeError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function writeInfo(string $message): void
    {
        $this->lines[] = $message;
    }

    public function writeNote(string $message): void
    {
        $this->lines[] = $message;
    }

    public function ask(string $question, ?callable $autocomplete = null): string
    {
        $ordinal = $this->nextOrdinal++;
        $answer = array_shift($this->answerQueue);
        if ($answer === null) {
            throw new McpInteractionRequiredException('ask', $question, [], $ordinal);
        }
        return $answer;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $ordinal = $this->nextOrdinal++;
        $answer = array_shift($this->answerQueue);
        if ($answer === null) {
            throw new McpInteractionRequiredException('confirm', $question, ['yes' => 'Yes', 'no' => 'No'], $ordinal);
        }
        return in_array(strtolower(trim($answer)), ['yes', 'true', '1'], strict: true);
    }

    public function chooseMultiple(string $question, array $choices, array $default = []): array
    {
        $ordinal = $this->nextOrdinal++;
        $answer = array_shift($this->answerQueue);
        if ($answer === null) {
            throw new McpInteractionRequiredException('chooseMultiple', $question, $choices, $ordinal);
        }
        return array_map(
            fn(string $part) => $this->resolveChoiceAnswer(trim($part), $choices),
            explode(',', $answer),
        );
    }

    public function chooseFromTable(string $question, array $headers, array $rows): string
    {
        $this->tables[] = ['headers' => $headers, 'rows' => array_values($rows)];
        $ordinal = $this->nextOrdinal++;
        $answer = array_shift($this->answerQueue);
        $choices = array_map(fn(array $cols) => implode(' | ', $cols), $rows);
        if ($answer === null) {
            throw new McpInteractionRequiredException('chooseFromTable', $question, $choices, $ordinal);
        }
        return $this->resolveChoiceAnswer($answer, $choices);
    }

    public function chooseFromMenu(ToolMenu $menu): string
    {
        $ordinal = $this->nextOrdinal++;
        $answer = array_shift($this->answerQueue);

        // Build choices map: shortName => label (available tools first, for readable MCP interaction)
        $choices = [];
        foreach ($menu->available() as $item) {
            $choices[$item->shortName] = $item->label;
        }

        if ($answer === null) {
            throw new McpInteractionRequiredException('chooseFromMenu', 'Choose a tool', $choices, $ordinal);
        }

        // Accept exact short name; fall back to substring match among available tools
        if (isset($choices[$answer])) {
            return $answer;
        }
        $matches = array_filter(
            array_keys($choices),
            fn(string $key) => str_contains($key, $answer),
        );
        if (count($matches) === 1) {
            return array_values($matches)[0];
        }
        throw new \InvalidArgumentException(sprintf(
            'Answer "%s" does not uniquely match any available tool. Available: %s',
            $answer,
            implode(', ', array_keys($choices)),
        ));
    }

    /**
     * Match a potentially sloppy MCP answer against the choice keys.
     * Tries exact key match first, then falls back to substring matching if exactly one key contains the answer.
     *
     * @param array<string, string> $choices
     */
    private function resolveChoiceAnswer(string $answer, array $choices): string
    {
        if (isset($choices[$answer])) {
            return $answer;
        }
        $matches = [];
        foreach (array_keys($choices) as $key) {
            if (str_contains($key, $answer)) {
                $matches[] = $key;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        throw new \InvalidArgumentException(sprintf(
            'Answer "%s" does not uniquely match any choice. Available: %s',
            $answer,
            implode(', ', array_keys($choices)),
        ));
    }

    public function progress(string $label, int $total, \Closure $callback): void
    {
        // MCP is stateless — no live progress bar; just run the callback and emit a line when done.
        $this->lines[] = $label . '…';
        $callback(static function(): void {});
        $this->lines[] = $label . ' done.';
    }

    /**
     * @return array{tables: list<array{headers: list<string>, rows: list<list<string>>}>, keyValues: list<array<string, string>>, lines: list<string>, errors: list<string>}
     */
    public function toStructuredOutput(): array
    {
        return [
            'tables' => $this->tables,
            'keyValues' => $this->keyValues,
            'lines' => $this->lines,
            'errors' => $this->errors,
        ];
    }

    /**
     * Formatted text representation of all buffered output.
     * @see \BufferingToolIO::getAllOutput() for the equivalent test pattern.
     */
    public function toTextOutput(): string
    {
        $parts = $this->lines;

        foreach ($this->errors as $error) {
            $parts[] = 'ERROR: ' . $error;
        }

        foreach ($this->keyValues as $pairs) {
            foreach ($pairs as $key => $value) {
                $parts[] = $key . ': ' . $value;
            }
        }

        foreach ($this->tables as $table) {
            $parts[] = implode(' | ', $table['headers']);
            foreach ($table['rows'] as $row) {
                $parts[] = implode(' | ', (array) $row);
            }
        }

        return implode("\n", $parts);
    }
}
