<?php

declare(strict_types=1);

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolMenu;

/**
 * @internal Test double for {@see ToolIOInterface} — captures all output and accepts pre-scripted choices/answers.
 */
final class BufferingToolIO implements ToolIOInterface
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<array{headers: list<string>, rows: list<list<string>>}> */
    private array $tables = [];

    /** @var list<array<string, string>> */
    private array $keyValues = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $answerQueue = [];

    /** @var list<string> */
    private array $choiceQueue = [];

    /** @var list<string> */
    private array $multiChoiceQueue = [];

    public function queueAnswer(string $answer): void
    {
        $this->answerQueue[] = $answer;
    }

    public function queueChoice(string $choice): void
    {
        $this->choiceQueue[] = $choice;
    }

    public function queueMultipleChoice(string $commaSeparatedKeys): void
    {
        $this->multiChoiceQueue[] = $commaSeparatedKeys;
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
        return array_shift($this->answerQueue) ?? '';
    }

    public function choose(string $question, array $choices): string
    {
        $queued = array_shift($this->choiceQueue);
        if ($queued !== null && isset($choices[$queued])) {
            return $queued;
        }
        // Fall back to first choice if queue is empty or key doesn't exist
        return (string)array_key_first($choices);
    }

    public function chooseMultiple(string $question, array $choices, array $default = []): array
    {
        $queued = array_shift($this->multiChoiceQueue) ?? '';
        return array_values(array_filter(
            array_map('trim', explode(',', $queued)),
            fn(string $k) => isset($choices[$k]),
        ));
    }

    public function chooseFromMenu(ToolMenu $menu): string
    {
        $queued = array_shift($this->choiceQueue);
        if ($queued !== null) {
            $item = $menu->findByShortName($queued);
            if ($item !== null && $item->available) {
                return $queued;
            }
        }
        // Fall back to first available tool
        return $menu->available()[0]->shortName ?? '';
    }

    /**
     * Returns all output concatenated for easy string assertions.
     * Key-values appear as "Key: value", tables as "col1 | col2" rows.
     */
    public function getAllOutput(): string
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
                $parts[] = implode(' | ', (array)$row);
            }
        }

        return implode("\n", $parts);
    }

    /** @return list<array<string, string>> */
    public function getKeyValues(): array
    {
        return $this->keyValues;
    }

    /** @return list<array{headers: list<string>, rows: list<list<string>>}> */
    public function getTables(): array
    {
        return $this->tables;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
