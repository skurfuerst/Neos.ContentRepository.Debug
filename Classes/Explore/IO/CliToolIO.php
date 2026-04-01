<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\IO;

use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Note;
use Laravel\Prompts\Table;
use Laravel\Prompts\TextPrompt;
use Neos\ContentRepository\Debug\Explore\ToolMenu;

/**
 * @internal Laravel Prompts implementation of {@see ToolIOInterface} for interactive CLI sessions.
 */
final class CliToolIO implements ToolIOInterface
{
    /**
     * @param array<string, array{position: string, groups: list<string>}> $menuColumns
     *   Column layout config from Settings.yaml (sorted by {@see ToolSelectionPrompt}).
     */
    public function __construct(
        private readonly array $menuColumns = [],
    ) {}

    public function writeTable(array $headers, array $rows): void
    {
        (new Table($headers, $rows))->display();
    }

    public function writeKeyValue(array $pairs): void
    {
        $rows = [];
        foreach ($pairs as $key => $value) {
            $rows[] = [$key, $value];
        }
        (new Table(rows: $rows))->display();
    }

    public function writeLine(string $text = ''): void
    {
        (new Note($text))->display();
    }

    public function writeError(string $message): void
    {
        (new Note($message, 'error'))->display();
    }

    public function writeInfo(string $message): void
    {
        (new Note($message, 'info'))->display();
    }

    public function writeNote(string $message): void
    {
        (new Note($message, 'warning'))->display();
    }

    public function ask(string $question, ?callable $autocomplete = null): string
    {
        return (string)(new TextPrompt(label: $question, required: false))->prompt();
    }

    public function confirm(string $question, bool $default = false): bool
    {
        return (bool)(new \Laravel\Prompts\ConfirmPrompt(label: $question, default: $default))->prompt();
    }

    public function chooseMultiple(string $question, array $choices, array $default = []): array
    {
        // laravel/prompts multiselect: arrow keys + space to toggle, returns selected keys.
        $selected = (new MultiSelectPrompt(label: $question, options: $choices, default: $default, scroll: count($choices)))->prompt();
        // Re-sort by position in $choices — laravel/prompts returns keys in toggle order, not options order.
        return array_values(array_intersect(array_keys($choices), $selected));
    }

    public function chooseFromTable(string $question, array $headers, array $rows): string
    {
        return (string)(new DataTablePrompt(
            headers: $headers,
            rows: $rows,
            label: $question,
            scroll: count($rows),
        ))->prompt();
    }

    public function chooseFromMenu(ToolMenu $menu): string
    {
        while (true) {
            $answer = (new ToolSelectionPrompt($menu, $this->menuColumns))->prompt();

            $selected = $menu->findByShortName((string)$answer);
            if ($selected === null || !$selected->available) {
                $missing = ($selected?->missingContextTypes ?? []) !== []
                    ? implode(', ', $selected->missingContextTypes)
                    : 'required context';
                $this->writeError(sprintf('"%s" is not available yet — needs: %s', $answer, $missing));
                continue;
            }
            return (string)$answer;
        }
    }

    public function progress(string $label, int $total, \Closure $callback): void
    {
        $bar = \Laravel\Prompts\progress(label: $label, steps: $total);
        $bar->start();
        $callback(static function() use ($bar): void { $bar->advance(); });
        $bar->finish();
    }
}
