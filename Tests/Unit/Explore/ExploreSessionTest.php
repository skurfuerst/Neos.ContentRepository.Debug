<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore;

use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use PHPUnit\Framework\TestCase;

class ExploreSessionTest extends TestCase
{
    private ToolContextRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolContextRegistry();
    }

    public function test_session_exits_when_exit_tool_is_selected(): void
    {
        $exitTool = new FakeExitTool();
        $dispatcher = new ToolDispatcher($this->registry, [$exitTool]);
        $io = new ScriptedToolIO(['0']); // pick first (and only) tool

        $session = new ExploreSession($dispatcher);
        $session->run(ToolContext::empty(), $io);

        self::assertTrue($exitTool->wasExecuted);
    }

    public function test_session_updates_context_and_loops(): void
    {
        $registry = new ToolContextRegistry();
        $registry->register(
            name: 'counter',
            type: FakeCounter::class,
            alias: 'c',
            fromString: fn(string $s) => new FakeCounter((int)$s),
            toString: fn(FakeCounter $v) => (string)$v->value,
        );
        $incrementTool = new FakeIncrementTool();
        $exitTool = new FakeExitTool();
        $dispatcher = new ToolDispatcher($registry, [$incrementTool, $exitTool]);
        // first pick: tool 0 (increment), second pick: tool 1 (exit)
        $io = new ScriptedToolIO(['0', '1']);

        $session = new ExploreSession($dispatcher);
        $session->run(ToolContext::empty()->with('counter', new FakeCounter(0)), $io);

        self::assertSame(1, $incrementTool->executionCount);
        self::assertTrue($exitTool->wasExecuted);
    }

    public function test_session_renders_menu_labels(): void
    {
        $exitTool = new FakeExitTool();
        $dispatcher = new ToolDispatcher($this->registry, [$exitTool]);
        $io = new ScriptedToolIO(['0']);

        $session = new ExploreSession($dispatcher);
        $session->run(ToolContext::empty(), $io);

        self::assertContains('Exit', $io->renderedChoiceLabels);
    }
}

// --- Fakes ---

final class FakeCounter
{
    public function __construct(public readonly int $value) {}
}

final class FakeExitTool implements ToolInterface
{
    public bool $wasExecuted = false;
    public function getMenuLabel(ToolContext $context): string { return 'Exit'; }
    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $this->wasExecuted = true;
        return ExploreSession::$EXIT;
    }
}

final class FakeIncrementTool implements ToolInterface
{
    public int $executionCount = 0;
    public function getMenuLabel(ToolContext $context): string { return 'Increment'; }
    public function execute(ToolIOInterface $io, FakeCounter $counter): ?ToolContext
    {
        $this->executionCount++;
        return ToolContext::empty()->with('counter', new FakeCounter($counter->value + 1));
    }
}

final class ScriptedToolIO implements ToolIOInterface
{
    /** @var list<string> */
    private array $choices;
    /** @var list<string> */
    public array $renderedChoiceLabels = [];

    public function __construct(array $choices)
    {
        $this->choices = $choices;
    }

    public function writeTable(array $headers, array $rows): void {}
    public function writeKeyValue(array $pairs): void {}
    public function writeLine(string $text = ''): void {}
    public function writeError(string $message): void {}
    public function ask(string $question, ?callable $autocomplete = null): string { return ''; }

    public function choose(string $question, array $choices): string
    {
        $this->renderedChoiceLabels = array_values($choices);
        return (string)array_keys($choices)[(int)array_shift($this->choices)];
    }
}
