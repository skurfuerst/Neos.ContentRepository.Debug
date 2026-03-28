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
