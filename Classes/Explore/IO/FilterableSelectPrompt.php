<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\IO;

use Laravel\Prompts\Key;
use Laravel\Prompts\SearchPrompt;
use Laravel\Prompts\Themes\Default\SearchPromptRenderer;

/**
 * @internal Searchable single-select prompt that always keeps an item highlighted.
 *
 * Extends {@see SearchPrompt} with two behavioral changes:
 * 1. After every search/filter, the first matching item is auto-highlighted so ENTER submits immediately.
 * 2. Page Up/Down support for jumping through long lists.
 *
 * Page Up/Down keys are intercepted BEFORE the parent's handler via a prepended listener
 * that sets {@see $pageJumpHandled} to prevent the parent's `default → search()` from
 * resetting the highlight.
 */
final class FilterableSelectPrompt extends SearchPrompt
{
    private bool $pageJumpHandled = false;

    public function __construct(
        string $label,
        \Closure $options,
        int $scroll = 5,
        string $placeholder = 'Type to filter...',
    ) {
        static::$themes['default'][static::class] = SearchPromptRenderer::class;

        // Prepend our Page Up/Down handler BEFORE parent registers its key handler.
        // Listeners fire in registration order, so this runs first.
        $this->on('key', function (string $key): void {
            $this->pageJumpHandled = false;
            $total = count($this->matches());
            if ($total === 0) {
                return;
            }
            match ($key) {
                Key::PAGE_UP => $this->handlePageJump(max(0, ($this->highlighted ?? 0) - $this->scroll)),
                Key::PAGE_DOWN => $this->handlePageJump(min($total - 1, ($this->highlighted ?? 0) + $this->scroll)),
                default => null,
            };
        });

        parent::__construct(
            label: $label,
            options: $options,
            placeholder: $placeholder,
            scroll: $scroll,
        );

        // Auto-highlight first item on initial render
        if ($this->matches() !== []) {
            $this->highlighted = 0;
        }
    }

    private function handlePageJump(int $index): void
    {
        $this->pageJumpHandled = true;
        $this->highlight($index);
    }

    protected function search(): void
    {
        // Skip search reset when Page Up/Down was handled — the parent's default
        // branch calls search() for any unrecognized key including escape sequences.
        if ($this->pageJumpHandled) {
            return;
        }

        parent::search();

        // Auto-highlight first match so ENTER submits immediately
        if ($this->matches() !== []) {
            $this->highlighted = 0;
        }
    }
}
