<?php

// No declare(strict_types=1) — the Renderer base class uses implicit __toString() coercion
// for the return value of __invoke(), which requires non-strict mode.
namespace Neos\ContentRepository\Debug\Explore\IO;

use Laravel\Prompts\Themes\Default\Renderer;

/**
 * @internal Renderer for {@see ToolSelectionPrompt}.
 *
 * Draws the full tool-selector UI on each frame:
 *   1. "What tool do you want to use?"
 *   2. "> {query}{dimmed-hint}"  (input line with cursor / autocomplete hint)
 *   3. Multi-column tool grid (highlighted item shown in inverse video)
 *   4. Context line: dimmed resume command, omitted when empty
 *   5. Help line: label of highlighted tool (left) + required context types, color-coded (right)
 *
 * On submit the entire UI is erased and replaced with `  # "shortName – label"`.
 */
final class ToolSelectionRenderer extends Renderer
{
    public function __invoke(ToolSelectionPrompt $prompt): string
    {
        if ($prompt->state === 'submit') {
            $item = $prompt->highlightedItem();
            $label = $item?->label ?? $prompt->highlighted;
            return $this->line('  ' . $this->dim('# "' . $prompt->highlighted . ' – ' . $label . '"'));
        }

        return $this
            ->line('  ' . $this->bold('What tool do you want to use?'))
            ->renderInputLine($prompt)
            ->newLine()
            ->renderGrid($prompt)
            ->newLine()
            ->renderContextLine($prompt)
            ->renderHelpLine($prompt);
    }

    private function renderInputLine(ToolSelectionPrompt $prompt): self
    {
        $query = $prompt->query;
        $hint  = $prompt->autocompleteHint;

        if ($hint !== null) {
            // Query text + dimmed remainder + blinking-cursor block at end
            $line = '  > ' . $query . $this->dim($hint);
        } elseif ($query === '') {
            $line = '  > ' . $this->inverse(' ');
        } else {
            $line = '  > ' . $query . $this->inverse(' ');
        }

        return $this->line($line);
    }

    private function renderGrid(ToolSelectionPrompt $prompt): self
    {
        $termWidth = $prompt->terminal()->cols() ?: 80;
        $numCols   = max(1, min(4, (int) floor($termWidth / ToolSelectionPrompt::COL_WIDTH)));

        $menu      = $prompt->menu;
        $groups    = $menu->groups();
        $colGroups = $prompt->columnGroupNames($numCols);

        // Determine max shortName width per group for alignment
        $snWidth = [];
        foreach ($groups as $group) {
            $max = 0;
            foreach ($menu->itemsForGroup($group) as $item) {
                $max = max($max, strlen($item->shortName));
            }
            $snWidth[$group] = $max;
        }

        $visible = $prompt->visibleItems();
        /** @var array<string, true> $visibleIndex shortName => true */
        $visibleIndex = [];
        foreach ($visible as $item) {
            $visibleIndex[$item->shortName] = true;
        }

        // Render each column into an array of lines
        /** @var array<int, list<string>> $colLines */
        $colLines = array_fill(0, $numCols, []);
        foreach ($colGroups as $colIdx => $colGroupNames) {
            foreach ($colGroupNames as $group) {
                $colLines[$colIdx][] = $this->cyan('── ') . $this->bold($group) . ' ';
                $pad         = $snWidth[$group];
                $labelWidth  = max(8, ToolSelectionPrompt::COL_WIDTH - $pad - 4);

                foreach ($menu->itemsForGroup($group) as $item) {
                    $label = $this->truncateVisible($item->label, $labelWidth);
                    $plain = sprintf('  %-' . $pad . 's  %s', $item->shortName, $label);

                    if ($item->shortName === $prompt->highlighted) {
                        $colLines[$colIdx][] = $this->inverse($plain);
                    } elseif (!isset($visibleIndex[$item->shortName])) {
                        // Filtered out by current query — show extra-dim
                        $colLines[$colIdx][] = $this->dim($plain);
                    } elseif (!$item->available) {
                        $colLines[$colIdx][] = $this->gray($plain);
                    } else {
                        $colLines[$colIdx][] = $plain;
                    }
                }
                $colLines[$colIdx][] = '';
            }
        }

        if ($numCols === 1) {
            foreach ($colLines[0] as $line) {
                $this->line('  ' . $line);
            }
            return $this;
        }

        $maxRows = max(array_map('count', $colLines));
        for ($row = 0; $row < $maxRows; $row++) {
            $parts = [];
            for ($col = 0; $col < $numCols; $col++) {
                $rawLine    = $colLines[$col][$row] ?? '';
                $visibleLen = mb_strlen(preg_replace("/\e\[[0-9;]*m/", '', $rawLine) ?? $rawLine);
                $parts[]    = $rawLine . str_repeat(' ', max(0, ToolSelectionPrompt::COL_WIDTH - $visibleLen));
            }
            $this->line('  ' . implode('', $parts));
        }

        return $this;
    }

    private function renderContextLine(ToolSelectionPrompt $prompt): self
    {
        if ($prompt->menu->contextDisplay === '') {
            return $this;
        }
        return $this->line('  ' . $this->dim($prompt->menu->contextDisplay));
    }

    private function renderHelpLine(ToolSelectionPrompt $prompt): self
    {
        $item = $prompt->highlightedItem();
        if ($item === null) {
            return $this->line('');
        }

        $termWidth = $prompt->terminal()->cols() ?: 80;
        $left      = $item->label;

        if ($item->requiredContextTypes === []) {
            // No context requirements — show simple ready/unavailable marker
            $right = $item->available ? $this->green('✓ ready') : $this->red('unavailable');
        } else {
            // Color-coded badges for each required context type: green = present, red = missing
            $missingSet = array_flip($item->missingContextTypes);
            $badges = [];
            foreach ($item->requiredContextTypes as $typeName) {
                $badges[] = isset($missingSet[$typeName])
                    ? $this->red($typeName)
                    : $this->green($typeName);
            }
            $right = implode('  ', $badges);
        }

        $rightVisible = mb_strlen(preg_replace("/\e\[[0-9;]*m/", '', $right) ?? $right);
        $leftMax      = max(0, $termWidth - $rightVisible - 6);
        $left         = $this->truncateVisible($left, $leftMax);
        $leftVisible  = mb_strlen($left);
        $padding      = max(1, $termWidth - $leftVisible - $rightVisible - 4);

        return $this->line('  ' . $left . str_repeat(' ', $padding) . $right);
    }

    /** Truncate to $maxLen visible characters, appending '…' if cut. */
    private function truncateVisible(string $text, int $maxLen): string
    {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }
        return mb_substr($text, 0, max(0, $maxLen - 1)) . '…';
    }
}
