<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\IO;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Neos\ContentRepository\Debug\Explore\ToolMenu;
use Neos\ContentRepository\Debug\Explore\ToolMenuItem;
use Neos\Utility\PositionalArraySorter;

/**
 * @internal Custom laravel/prompts widget for tool selection.
 *
 * Renders a type-ahead input field over a multi-column tool grid. Maintains the following
 * public state that {@see ToolSelectionRenderer} reads to draw each frame:
 *
 * - {@see $query}            Current typed text (used to filter and autocomplete shortNames)
 * - {@see $highlighted}      shortName of the currently focused tool
 * - {@see $autocompleteHint} Dimmed suffix shown after the cursor when exactly one shortName matches
 * - {@see $sortedColumns}    Column definitions from Settings.yaml, ordered by position
 */
final class ToolSelectionPrompt extends Prompt
{
    /** Target column width in characters — shared with {@see ToolSelectionRenderer}. */
    public const COL_WIDTH = 38;

    /** Current type-ahead filter string. */
    public string $query = '';

    /** shortName of the currently highlighted (focused) tool. */
    public string $highlighted = '';

    /** Remaining characters of the single-match shortName shown dimmed after the cursor. Null when no unique match. */
    public ?string $autocompleteHint = null;

    /**
     * Column definitions from Settings.yaml, sorted by `position`.
     *
     * Each entry: `['groups' => list<string>]`
     *
     * @var list<array{groups: list<string>}>
     */
    public readonly array $sortedColumns;

    /**
     * @param array<string, array{position: string, groups: list<string>}> $menuColumns
     *   Keyed column config from Settings.yaml — sorted by `position` at construction time.
     */
    public function __construct(
        public readonly ToolMenu $menu,
        array $menuColumns = [],
    ) {
        // Prompt declares $required and $validate without defaults — initialize before any Prompt code runs.
        $this->required = false;
        $this->validate = null;

        static::$themes['default'][static::class] = ToolSelectionRenderer::class;

        $this->sortedColumns = array_values((new PositionalArraySorter($menuColumns))->toArray());

        $available = $menu->available();
        $this->highlighted = $available !== [] ? $available[0]->shortName : '';

        $this->on('key', function (string $key): void {
            match (true) {
                $key === Key::ENTER                                => $this->handleEnter(),
                in_array($key, [Key::BACKSPACE, Key::CTRL_H], true) => $this->handleBackspace(),
                $key === Key::TAB                                  => $this->acceptAutocomplete(),
                // Right-arrow accepts hint when one is showing, otherwise navigates
                in_array($key, [Key::RIGHT, Key::RIGHT_ARROW], true)
                    => $this->autocompleteHint !== null ? $this->acceptAutocomplete() : $this->navigateRight(),
                in_array($key, [Key::LEFT, Key::LEFT_ARROW], true)  => $this->navigateLeft(),
                in_array($key, [Key::UP, Key::UP_ARROW], true)      => $this->navigateUp(),
                in_array($key, [Key::DOWN, Key::DOWN_ARROW], true)  => $this->navigateDown(),
                mb_strlen($key) === 1 && mb_ord($key) >= 32         => $this->handleTypedChar($key),
                default => null,
            };
        });
    }

    public function value(): mixed
    {
        return $this->highlighted;
    }

    // ── Public state-transition methods (also used directly by tests) ─────────

    /**
     * Append a printable character to the query, update filter and autocomplete hint.
     *
     * Public so unit tests can drive state transitions without a live terminal.
     */
    public function handleTypedChar(string $char): void
    {
        $this->query .= $char;
        $this->syncHighlightToQuery();
        $this->refreshAutocompleteHint();
    }

    /**
     * Remove the last character from the query and refresh state.
     *
     * Public so unit tests can drive state transitions without a live terminal.
     */
    public function handleBackspace(): void
    {
        if ($this->query === '') {
            return;
        }
        $this->query = mb_substr($this->query, 0, mb_strlen($this->query) - 1);
        $this->syncHighlightToQuery();
        $this->refreshAutocompleteHint();
    }

    /**
     * Accept the current autocomplete hint, completing the query to the full shortName.
     *
     * Public so unit tests can drive state transitions without a live terminal.
     */
    public function acceptAutocomplete(): void
    {
        if ($this->autocompleteHint === null) {
            return;
        }
        $this->query .= $this->autocompleteHint;
        $this->autocompleteHint = null;
        $this->syncHighlightToQuery();
    }

    // ── Computed views ────────────────────────────────────────────────────────

    /**
     * Items whose shortName starts with the current query (case-insensitive), in menu order.
     * Returns all items when query is empty.
     *
     * @return list<ToolMenuItem>
     */
    public function visibleItems(): array
    {
        if ($this->query === '') {
            return $this->menu->items;
        }
        $lower = strtolower($this->query);
        return array_values(array_filter(
            $this->menu->items,
            fn(ToolMenuItem $item) => str_starts_with(strtolower($item->shortName), $lower),
        ));
    }

    /** The currently highlighted tool item, or null if nothing is highlighted. */
    public function highlightedItem(): ?ToolMenuItem
    {
        foreach ($this->menu->items as $item) {
            if ($item->shortName === $this->highlighted) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Group names assigned to each column, based on {@see $sortedColumns} config.
     * Groups not in the config are appended to the last column.
     * Uses all menu groups (not the filtered subset) for a stable layout.
     * Used by {@see ToolSelectionRenderer} for drawing and by {@see columnItems()} for navigation.
     *
     * @return array<int, list<string>>  colIdx => group names in display order
     */
    public function columnGroupNames(int $numCols): array
    {
        $allGroups = $this->distinctGroups($this->menu->items);

        $result   = array_fill(0, $numCols, []);
        $assigned = [];
        foreach ($this->sortedColumns as $configIdx => $colDef) {
            $colIdx = $configIdx % $numCols; // one config entry = one column slot
            foreach ($colDef['groups'] as $group) {
                if (in_array($group, $allGroups, strict: true)) {
                    $result[$colIdx][] = $group;
                    $assigned[]        = $group;
                }
            }
        }
        $lastCol = $numCols - 1;
        foreach ($allGroups as $group) {
            if (!in_array($group, $assigned, strict: true)) {
                $result[$lastCol][] = $group;
            }
        }
        return $result;
    }

    /**
     * Visible items grouped into $numCols columns according to the sorted column config.
     * Only includes items matching the current query filter.
     * Used by navigation methods for 2-D movement.
     *
     * @return array<int, list<ToolMenuItem>>  colIdx => flat list of visible items in display order
     */
    public function columnItems(int $numCols): array
    {
        $visibleSet = [];
        foreach ($this->visibleItems() as $item) {
            $visibleSet[$item->shortName] = $item;
        }

        $cols = array_fill(0, $numCols, []);
        foreach ($this->columnGroupNames($numCols) as $colIdx => $groupNames) {
            foreach ($groupNames as $group) {
                foreach ($this->menu->items as $item) {
                    if ($item->group === $group && isset($visibleSet[$item->shortName])) {
                        $cols[$colIdx][] = $item;
                    }
                }
            }
        }
        return $cols;
    }

    // ── Private key handlers ──────────────────────────────────────────────────

    private function handleEnter(): void
    {
        $item = $this->highlightedItem();
        if ($item !== null && $item->available) {
            $this->submit();
        }
    }

    private function navigateUp(): void
    {
        $this->moveInColumn(direction: -1);
    }

    private function navigateDown(): void
    {
        $this->moveInColumn(direction: +1);
    }

    private function navigateLeft(): void
    {
        $this->jumpToAdjacentColumn(direction: -1);
    }

    private function navigateRight(): void
    {
        $this->jumpToAdjacentColumn(direction: +1);
    }

    /**
     * Move up (-1) or down (+1) within the current column, wrapping at the ends.
     */
    private function moveInColumn(int $direction): void
    {
        $numCols = $this->currentNumCols();
        $cols = $this->columnItems($numCols);

        [$colIdx, $rowIdx] = $this->findPosition($cols);
        if ($colIdx === null) {
            return;
        }
        $col = $cols[$colIdx];
        if ($col === []) {
            return;
        }
        $newRow = ($rowIdx + $direction + count($col)) % count($col);
        $this->highlighted = $col[$newRow]->shortName;
    }

    /**
     * Jump left (-1) or right (+1) to the same row index in the adjacent column.
     * If the target column is shorter, clamps to its last item.
     */
    private function jumpToAdjacentColumn(int $direction): void
    {
        $numCols = $this->currentNumCols();
        $cols = $this->columnItems($numCols);

        // Find non-empty columns only
        $nonEmptyCols = array_keys(array_filter($cols, fn($c) => $c !== []));
        if (count($nonEmptyCols) <= 1) {
            return;
        }

        [$colIdx, $rowIdx] = $this->findPosition($cols);
        if ($colIdx === null) {
            $this->highlighted = $cols[$nonEmptyCols[0]][0]->shortName;
            return;
        }

        $currentPos = array_search($colIdx, $nonEmptyCols, strict: true);
        $targetPos  = ($currentPos + $direction + count($nonEmptyCols)) % count($nonEmptyCols);
        $targetCol  = $cols[$nonEmptyCols[$targetPos]];
        $targetRow  = min($rowIdx, count($targetCol) - 1);
        $this->highlighted = $targetCol[$targetRow]->shortName;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function currentNumCols(): int
    {
        $termWidth = static::terminal()->cols() ?: 80;
        return max(1, min(4, (int) floor($termWidth / self::COL_WIDTH)));
    }

    /**
     * Find the [colIdx, rowIdx] of the currently highlighted item within the given column layout.
     * Returns [null, 0] if not found.
     *
     * @param  array<int, list<ToolMenuItem>> $cols
     * @return array{int|null, int}
     */
    private function findPosition(array $cols): array
    {
        foreach ($cols as $colIdx => $colItems) {
            foreach ($colItems as $rowIdx => $item) {
                if ($item->shortName === $this->highlighted) {
                    return [$colIdx, $rowIdx];
                }
            }
        }
        return [null, 0];
    }

    private function syncHighlightToQuery(): void
    {
        $visible = $this->visibleItems();
        if ($visible === []) {
            return;
        }
        // Exact match always wins — even over a currently-highlighted prefix match
        $lower = strtolower($this->query);
        foreach ($visible as $item) {
            if (strtolower($item->shortName) === $lower) {
                $this->highlighted = $item->shortName;
                return;
            }
        }
        // Keep current highlight if still visible; otherwise jump to first visible item
        foreach ($visible as $item) {
            if ($item->shortName === $this->highlighted) {
                return;
            }
        }
        $this->highlighted = $visible[0]->shortName;
    }

    private function refreshAutocompleteHint(): void
    {
        if ($this->query === '') {
            $this->autocompleteHint = null;
            return;
        }
        $visible = $this->visibleItems();
        if (count($visible) === 1) {
            $sn = $visible[0]->shortName;
            $remainder = mb_substr($sn, mb_strlen($this->query));
            $this->autocompleteHint = $remainder !== '' ? $remainder : null;
            return;
        }
        $this->autocompleteHint = null;
    }

    /**
     * @param list<ToolMenuItem> $items
     * @return list<string>
     */
    private function distinctGroups(array $items): array
    {
        $seen = [];
        foreach ($items as $item) {
            $seen[$item->group] = true;
        }
        return array_keys($seen);
    }

    /**
     * @param list<ToolMenuItem> $items
     */
    private function indexOf(array $items, string $shortName): ?int
    {
        foreach ($items as $i => $item) {
            if ($item->shortName === $shortName) {
                return $i;
            }
        }
        return null;
    }
}
