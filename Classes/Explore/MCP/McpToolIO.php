<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\MCP;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;

/**
 * @internal MCP transport for {@see ToolIOInterface} — buffers all output and consumes pre-supplied answers
 *           for stateless request/response MCP tool calls.
 *
 * When a tool calls {@see ask()} or {@see choose()} and no pre-supplied answer remains,
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

    public function ask(string $question, ?callable $autocomplete = null): string
    {
        $ordinal = $this->nextOrdinal++;
        $answer = array_shift($this->answerQueue);
        if ($answer === null) {
            throw new McpInteractionRequiredException('ask', $question, [], $ordinal);
        }
        return $answer;
    }

    public function choose(string $question, array $choices): string
    {
        $ordinal = $this->nextOrdinal++;
        $answer = array_shift($this->answerQueue);
        if ($answer === null) {
            throw new McpInteractionRequiredException('choose', $question, $choices, $ordinal);
        }
        return $this->resolveChoiceAnswer($answer, $choices);
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
