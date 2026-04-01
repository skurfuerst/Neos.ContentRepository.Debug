<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore\Tool\Session;

use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\ToolContextSerializer;
use Neos\ContentRepository\Debug\Explore\Tool\Session\ExitTool;
use Neos\ContentRepository\Debug\Explore\Tool\Session\ShowResumeCommandTool;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolMenu;
use PHPUnit\Framework\TestCase;

class SessionToolsTest extends TestCase
{
    // --- ExitTool ---

    public function test_exit_tool_label(): void
    {
        $tool = new ExitTool();
        self::assertSame('Exit', $tool->getMenuLabel(ToolContext::empty()));
    }

    public function test_exit_tool_returns_exit_sentinel(): void
    {
        $tool = new ExitTool();
        $result = $tool->execute(new SpyToolIO());
        self::assertSame(ExploreSession::exit(), $result);
    }

    // --- ShowResumeCommandTool ---

    public function test_show_resume_command_label(): void
    {
        $tool = new ShowResumeCommandTool();
        self::assertSame('Show resume command', $tool->getMenuLabel(ToolContext::empty()));
    }

    public function test_show_resume_command_prints_bare_command_for_empty_context(): void
    {
        $registry = new ToolContextRegistry();
        $tool = self::makeShowResumeCommandTool(new ToolContextSerializer($registry));
        $io = new SpyToolIO();

        $result = $tool->execute($io, ToolContext::empty());

        self::assertNull($result);
        self::assertStringContainsString('./flow cr:explore', $io->lastLine);
    }

    public function test_show_resume_command_includes_serialised_context(): void
    {
        $registry = new ToolContextRegistry();
        $registry->register(
            name: 'node',
            type: FakeNodeId::class,
            alias: 'n',
            fromString: fn(string $s) => new FakeNodeId($s),
            toString: fn(FakeNodeId $v) => $v->value,
        );
        $tool = self::makeShowResumeCommandTool(new ToolContextSerializer($registry));
        $io = new SpyToolIO();
        $ctx = ToolContext::empty()->with('node', new FakeNodeId('abc-123'));

        $tool->execute($io, $ctx);

        self::assertStringContainsString('--node=abc-123', $io->lastLine);
    }

    private static function makeShowResumeCommandTool(ToolContextSerializer $serializer): ShowResumeCommandTool
    {
        $tool = new ShowResumeCommandTool();
        (new \ReflectionProperty($tool, 'serializer'))->setValue($tool, $serializer);
        return $tool;
    }
}

// --- Fakes ---

final class FakeNodeId
{
    public function __construct(public readonly string $value) {}
}

final class SpyToolIO implements ToolIOInterface
{
    public string $lastLine = '';
    public function writeTable(array $headers, array $rows): void {}
    public function writeKeyValue(array $pairs): void {}
    public function writeLine(string $text = ''): void { $this->lastLine = $text; }
    public function writeError(string $message): void {}
    public function writeInfo(string $message): void {}
    public function writeNote(string $message): void {}
    public function chooseFromTable(string $question, array $headers, array $rows): string { return (string)array_key_first($rows); }
    public function ask(string $question, ?callable $autocomplete = null): string { return ''; }
    public function confirm(string $question, bool $default = false): bool { return $default; }
    public function progress(string $label, int $total, \Closure $callback): void { $callback(static function(): void {}); }
    public function chooseMultiple(string $question, array $choices, array $default = []): array { return $default; }
    public function chooseFromMenu(ToolMenu $menu): string { return $menu->available()[0]->shortName ?? ''; }
}
