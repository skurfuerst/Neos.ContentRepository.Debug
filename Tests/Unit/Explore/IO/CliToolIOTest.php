<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore\IO;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Neos\ContentRepository\Debug\Explore\IO\CliToolIO;
use PHPUnit\Framework\TestCase;

/**
 * Tests {@see CliToolIO} interactive methods using Laravel Prompts' fake terminal.
 *
 * @see Prompt::fake() for how key presses are simulated without a live TTY.
 */
class CliToolIOTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
    }

    // ── choose() ─────────────────────────────────────────────────────────────

    public function test_choose_returns_first_key_on_immediate_enter(): void
    {
        // First item is auto-highlighted, ENTER submits immediately
        Prompt::fake([Key::ENTER]);

        $io = new CliToolIO();
        $result = $io->choose('Pick one', [
            'alpha' => 'First option',
            'beta'  => 'Second option',
            'gamma' => 'Third option',
        ]);

        self::assertSame('alpha', $result);
    }

    public function test_choose_returns_second_key_with_arrow(): void
    {
        // DOWN navigates to second item
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $io = new CliToolIO();
        $result = $io->choose('Pick one', [
            'alpha' => 'First option',
            'beta'  => 'Second option',
            'gamma' => 'Third option',
        ]);

        self::assertSame('beta', $result);
    }

    public function test_choose_filters_by_search_and_selects(): void
    {
        // Type "Sec" to filter → first match auto-highlighted → ENTER submits
        Prompt::fake(['S', 'e', 'c', Key::ENTER]);

        $io = new CliToolIO();
        $result = $io->choose('Pick one', [
            'alpha' => 'First option',
            'beta'  => 'Second option',
            'gamma' => 'Third option',
        ]);

        self::assertSame('beta', $result);
    }

    public function test_choose_page_down_jumps_through_list(): void
    {
        // 20 options, terminal has 30 lines → scroll capped to 30-7=23 (fits all 20).
        // PAGE_DOWN jumps by scroll amount, clamped to last item.
        $choices = [];
        for ($i = 1; $i <= 20; $i++) {
            $choices["k{$i}"] = "Item {$i}";
        }

        // PAGE_DOWN jumps from 0 towards end, ENTER submits
        Prompt::fake([Key::PAGE_DOWN, Key::ENTER]);
        Prompt::terminal()->shouldReceive('lines')->andReturn(30);

        $io = new CliToolIO();
        $result = $io->choose('Pick', $choices);

        // Should have jumped past first item (exact position depends on scroll size)
        self::assertNotSame('k1', $result);
    }

    public function test_choose_shows_all_options_without_scrollbar(): void
    {
        $choices = [];
        for ($i = 1; $i <= 30; $i++) {
            $choices["key{$i}"] = "Option {$i}";
        }

        Prompt::fake([Key::ENTER]);
        Prompt::terminal()->shouldReceive('lines')->andReturn(100);

        $io = new CliToolIO();
        $io->choose('Pick one', $choices);

        $output = Prompt::strippedContent();
        self::assertStringContainsString('Option 1', $output);
        self::assertStringContainsString('Option 30', $output);
    }

    public function test_choose_submit_frame_shows_only_selected_value(): void
    {
        Prompt::fake([Key::ENTER]);

        $io = new CliToolIO();
        $io->choose('Pick one', [
            'alpha' => 'First option',
            'beta'  => 'Second option',
        ]);

        $output = Prompt::strippedContent();
        $lastBox = substr($output, strrpos($output, '┌'));
        self::assertStringContainsString('First option', $lastBox);
        self::assertStringNotContainsString('Second option', $lastBox);
    }

    // ── writeTable() ──────────────────────────────────────────────────────────

    public function test_writeTable_renders_headers_and_rows(): void
    {
        Prompt::fake([]);

        $io = new CliToolIO();
        $io->writeTable(['Name', 'Type'], [
            ['home', 'Document'],
            ['about', 'Page'],
        ]);

        $output = Prompt::strippedContent();
        self::assertStringContainsString('Name', $output);
        self::assertStringContainsString('Type', $output);
        self::assertStringContainsString('home', $output);
        self::assertStringContainsString('Document', $output);
        self::assertStringContainsString('about', $output);
        self::assertStringContainsString('Page', $output);
    }

    public function test_writeTable_uses_box_drawing_characters(): void
    {
        Prompt::fake([]);

        $io = new CliToolIO();
        $io->writeTable(['Col'], [['val']]);

        $output = Prompt::strippedContent();
        self::assertStringContainsString('─', $output);
        self::assertStringContainsString('│', $output);
    }

    // ── ask() ─────────────────────────────────────────────────────────────────

    public function test_ask_returns_typed_text(): void
    {
        Prompt::fake(['h', 'e', 'l', 'l', 'o', Key::ENTER]);

        $io = new CliToolIO();
        $result = $io->ask('Enter value');

        self::assertSame('hello', $result);
    }

    public function test_ask_returns_empty_string_on_immediate_enter(): void
    {
        Prompt::fake([Key::ENTER]);

        $io = new CliToolIO();
        $result = $io->ask('Enter value');

        self::assertSame('', $result);
    }

    // ── writeKeyValue() ───────────────────────────────────────────────────────

    public function test_writeKeyValue_renders_keys_and_values(): void
    {
        Prompt::fake([]);

        $io = new CliToolIO();
        $io->writeKeyValue(['Node Type' => 'Neos.Neos:Page', 'ID' => 'abc-123']);

        $output = Prompt::strippedContent();
        self::assertStringContainsString('Node Type', $output);
        self::assertStringContainsString('Neos.Neos:Page', $output);
        self::assertStringContainsString('ID', $output);
        self::assertStringContainsString('abc-123', $output);
    }

    // ── writeError() ──────────────────────────────────────────────────────────

    public function test_writeError_renders_message(): void
    {
        Prompt::fake([]);

        $io = new CliToolIO();
        $io->writeError('Node not found');

        $output = Prompt::strippedContent();
        self::assertStringContainsString('Node not found', $output);
    }
}
