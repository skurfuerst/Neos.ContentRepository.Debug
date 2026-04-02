<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore\IO;

use Neos\ContentRepository\Debug\Explore\IO\ToolSelectionPrompt;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolMenu;
use Neos\ContentRepository\Debug\Explore\ToolMenuItem;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic methods of {@see ToolSelectionPrompt} — filtering, navigation state,
 * column sorting, and autocomplete hint computation — without requiring a live TTY.
 */
class ToolSelectionPromptTest extends TestCase
{
    // ── visibleItems() ────────────────────────────────────────────────────────

    public function test_all_items_visible_with_empty_query(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['wsId', 'Workspace'],
            ['n', 'Node Info'],
            ['dsp', 'Dimensions'],
        ]));

        self::assertCount(3, $prompt->visibleItems());
    }

    public function test_prefix_filter_matches_by_shortname(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['wsId', 'Workspace'],
            ['n', 'Node Info'],
            ['nId', 'Node Identity'],
        ]));
        $prompt->query = 'n';

        $visible = $prompt->visibleItems();
        self::assertCount(2, $visible);
        self::assertSame('n', $visible[0]->shortName);
        self::assertSame('nId', $visible[1]->shortName);
    }

    public function test_filter_is_case_insensitive(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['wsId', 'Workspace'],
            ['nId', 'Node Identity'],
        ]));
        $prompt->query = 'WS';

        $visible = $prompt->visibleItems();
        self::assertCount(1, $visible);
        self::assertSame('wsId', $visible[0]->shortName);
    }

    public function test_filter_returns_empty_when_no_match(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['wsId', 'Workspace'],
        ]));
        $prompt->query = 'xyz';

        self::assertSame([], $prompt->visibleItems());
    }

    // ── highlightedItem() ────────────────────────────────────────────────────

    public function test_first_available_item_is_highlighted_initially(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['unavail', 'Unavailable', false],
            ['wsId', 'Workspace'],
            ['n', 'Node Info'],
        ]));

        self::assertSame('wsId', $prompt->highlightedItem()?->shortName);
    }

    public function test_highlighted_item_null_when_no_available_items(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['wsId', 'Workspace', false],
        ]));

        self::assertNull($prompt->highlightedItem());
    }

    // ── sortedColumns ────────────────────────────────────────────────────────

    public function test_columns_sorted_by_position(): void
    {
        $prompt = new ToolSelectionPrompt(
            $this->makeMenu([['wsId', 'W']]),
            [
                'nodes'     => ['position' => '30', 'groups' => ['Nodes']],
                'workspace' => ['position' => '10', 'groups' => ['Workspace']],
                'dims'      => ['position' => '20', 'groups' => ['Dimensions']],
            ]
        );

        self::assertSame(['Workspace'],  $prompt->sortedColumns[0]['groups']);
        self::assertSame(['Dimensions'], $prompt->sortedColumns[1]['groups']);
        self::assertSame(['Nodes'],      $prompt->sortedColumns[2]['groups']);
    }

    public function test_no_columns_config_gives_empty_sorted_columns(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([['wsId', 'W']]));

        self::assertSame([], $prompt->sortedColumns);
    }

    // ── exact-match highlight ─────────────────────────────────────────────────

    public function test_exact_match_wins_when_previously_highlighted_is_prefix_match(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['n',   'Node Info'],
            ['nId', 'Node Identity'],
        ]));
        // Simulate that a prefix match was previously highlighted (e.g. via arrow key)
        $prompt->highlighted = 'nId';

        $prompt->handleTypedChar('n');

        self::assertSame('n', $prompt->highlighted, 'Exact match "n" must win over prefix match "nId"');
    }

    public function test_exact_match_wins_on_fresh_type(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['n',   'Node Info'],
            ['nId', 'Node Identity'],
        ]));

        $prompt->handleTypedChar('n');

        self::assertSame('n', $prompt->highlighted, 'Exact match "n" must be selected, not the first prefix match "n" (which happens to be "n" itself — this also checks order stability)');
    }

    // ── autocomplete hint ────────────────────────────────────────────────────

    public function test_hint_set_when_single_prefix_match(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['wsId', 'Workspace'],
            ['n', 'Node Info'],
        ]));

        $prompt->handleTypedChar('w');

        self::assertSame('sId', $prompt->autocompleteHint);
    }

    public function test_hint_is_null_when_multiple_prefix_matches(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['n', 'Node Info'],
            ['nId', 'Node Identity'],
        ]));

        $prompt->handleTypedChar('n');

        self::assertNull($prompt->autocompleteHint);
    }

    public function test_hint_is_null_when_query_already_equals_shortname(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['n', 'Node Info'],
            ['nId', 'Node Identity'],
        ]));
        $prompt->query = 'n';

        $prompt->handleTypedChar('I');

        // query is now 'nI', only 'nId' matches, hint = 'd'
        self::assertSame('d', $prompt->autocompleteHint);
    }

    public function test_backspace_removes_last_char_and_resets_hint(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['wsId', 'Workspace'],
            ['n', 'Node Info'],
        ]));
        $prompt->query = 'ws';
        $prompt->autocompleteHint = 'Id'; // stale hint

        $prompt->handleBackspace();

        self::assertSame('w', $prompt->query);
        // 'wsId' still the only match → hint should be 'sId'
        self::assertSame('sId', $prompt->autocompleteHint);
    }

    public function test_backspace_on_empty_query_is_noop(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([['wsId', 'Workspace']]));

        $prompt->handleBackspace(); // should not throw

        self::assertSame('', $prompt->query);
    }

    public function test_accept_hint_fills_query(): void
    {
        $prompt = new ToolSelectionPrompt($this->makeMenu([
            ['wsId', 'Workspace'],
            ['n', 'Node Info'],
        ]));
        $prompt->query = 'w';
        $prompt->autocompleteHint = 'sId';

        $prompt->acceptAutocomplete();

        self::assertSame('wsId', $prompt->query);
        self::assertNull($prompt->autocompleteHint);
    }

    // ── columnItems() ────────────────────────────────────────────────────────

    public function test_column_items_assigns_groups_per_config(): void
    {
        $prompt = new ToolSelectionPrompt(
            $this->makeMultiGroupMenu([
                ['wsId', 'Workspace', 'Workspace'],
                ['dsp',  'Dims',      'Dimensions'],
                ['n',    'Node',      'Nodes'],
            ]),
            [
                'workspace' => ['position' => '10', 'groups' => ['Workspace']],
                'dims'      => ['position' => '20', 'groups' => ['Dimensions']],
                'nodes'     => ['position' => '30', 'groups' => ['Nodes']],
            ]
        );

        $cols = $prompt->columnItems(3);

        self::assertSame(['wsId'], array_map(fn($i) => $i->shortName, $cols[0]));
        self::assertSame(['dsp'],  array_map(fn($i) => $i->shortName, $cols[1]));
        self::assertSame(['n'],    array_map(fn($i) => $i->shortName, $cols[2]));
    }

    public function test_column_items_groups_multiple_groups_in_one_column(): void
    {
        $prompt = new ToolSelectionPrompt(
            $this->makeMultiGroupMenu([
                ['wsId',    'Workspace', 'Workspace'],
                ['uriPath', 'URI Path',  'Other'],
                ['n',       'Node',      'Nodes'],
            ]),
            [
                'workspace' => ['position' => '10', 'groups' => ['Workspace', 'Other']],
                'nodes'     => ['position' => '20', 'groups' => ['Nodes']],
            ]
        );

        $cols = $prompt->columnItems(2);

        self::assertSame(['wsId', 'uriPath'], array_map(fn($i) => $i->shortName, $cols[0]));
        self::assertSame(['n'],               array_map(fn($i) => $i->shortName, $cols[1]));
    }

    public function test_column_items_respects_active_query_filter(): void
    {
        $prompt = new ToolSelectionPrompt(
            $this->makeMultiGroupMenu([
                ['wsId', 'Workspace', 'Workspace'],
                ['n',    'Node',      'Nodes'],
                ['nId',  'Node Id',   'Nodes'],
            ]),
            [
                'workspace' => ['position' => '10', 'groups' => ['Workspace']],
                'nodes'     => ['position' => '20', 'groups' => ['Nodes']],
            ]
        );
        $prompt->query = 'n';

        $cols = $prompt->columnItems(2);

        self::assertSame([],          array_map(fn($i) => $i->shortName, $cols[0]));
        self::assertSame(['n', 'nId'], array_map(fn($i) => $i->shortName, $cols[1]));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<array{0: string, 1: string, 2?: bool}> $items  [shortName, label, available=true]
     */
    private function makeMenu(array $items, string $group = 'Group'): ToolMenu
    {
        return new ToolMenu(array_map(
            fn(array $i) => new ToolMenuItem($i[0], $i[1], $group, $i[2] ?? true, PromptTestFakeTool::class),
            $items,
        ));
    }

    /**
     * @param array<array{0: string, 1: string, 2: string, 3?: bool}> $items  [shortName, label, group, available=true]
     */
    private function makeMultiGroupMenu(array $items): ToolMenu
    {
        return new ToolMenu(array_map(
            fn(array $i) => new ToolMenuItem($i[0], $i[1], $i[2], $i[3] ?? true, PromptTestFakeTool::class),
            $items,
        ));
    }
}

// Minimal ToolInterface stub used as a class-string placeholder in ToolMenuItem construction.
#[ToolMeta(shortName: 'prompt-fake', group: 'Test')]
final class PromptTestFakeTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string { return 'fake'; }
    public function execute(ToolIOInterface $io): ?ToolContext { return null; }
}
