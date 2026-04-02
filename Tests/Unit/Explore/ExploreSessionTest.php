<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore;

use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ToolBuilder;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\ContentRepository\Debug\Explore\ToolMenu;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use PHPUnit\Framework\TestCase;

class ExploreSessionTest extends TestCase
{
    private ToolContextRegistry $registry;
    private ToolBuilder $builder;

    protected function setUp(): void
    {
        $this->registry = new ToolContextRegistry();
        $this->registry->register(
            name: 'counter',
            type: SessionFakeCounter::class,
            alias: 'c',
            fromString: fn(string $s) => new SessionFakeCounter((int)$s),
            toString: fn(SessionFakeCounter $v) => (string)$v->value,
        );
        $this->builder = $this->makeBuilder($this->registry);
    }

    public function test_session_exits_when_exit_tool_is_selected(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [SessionFakeExitTool::class]);
        $io = new SessionScriptedToolIO(['session-exit']); // pick by short name

        $session = new ExploreSession($dispatcher);
        $session->run(ToolContext::empty(), $io);

        self::assertSame(1, SessionFakeExitTool::$executionCount);
    }

    public function test_session_updates_context_and_loops(): void
    {
        SessionFakeExitTool::$executionCount = 0;
        SessionFakeIncrementTool::$executionCount = 0;

        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [
            SessionFakeIncrementTool::class,
            SessionFakeExitTool::class,
        ]);
        // first pick: increment, second pick: exit
        $io = new SessionScriptedToolIO(['session-increment', 'session-exit']);

        $session = new ExploreSession($dispatcher);
        $session->run(ToolContext::empty()->with('counter', new SessionFakeCounter(0)), $io);

        self::assertSame(1, SessionFakeIncrementTool::$executionCount);
        self::assertSame(1, SessionFakeExitTool::$executionCount);
    }

    public function test_session_renders_menu_labels(): void
    {
        SessionFakeExitTool::$executionCount = 0;

        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [SessionFakeExitTool::class]);
        $io = new SessionScriptedToolIO(['session-exit']);

        $session = new ExploreSession($dispatcher);
        $session->run(ToolContext::empty(), $io);

        self::assertContains('Exit', $io->renderedChoiceLabels);
    }

    public function test_session_calls_context_renderer_before_each_menu(): void
    {
        SessionFakeExitTool::$executionCount = 0;

        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [SessionFakeExitTool::class]);
        $io = new SessionScriptedToolIO(['session-exit']);

        $renderCount = 0;
        $session = new ExploreSession($dispatcher, function (ToolContext $ctx) use (&$renderCount): string {
            $renderCount++;
            return '';
        });
        $session->run(ToolContext::empty(), $io);

        self::assertSame(1, $renderCount);
    }

    // --- Helpers ---

    private function makeBuilder(ToolContextRegistry $registry): ToolBuilder
    {
        $reflectionService = $this->createStub(ReflectionService::class);
        $reflectionService->method('getMethodParameters')->willReturn([]);

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willThrowException(new \RuntimeException('ObjectManager not available in unit tests'));

        return new ToolBuilder($registry, $objectManager, $reflectionService);
    }
}

// --- Fakes ---

final class SessionFakeCounter
{
    public function __construct(public readonly int $value) {}
}

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'session-exit', group: 'Test')]
final class SessionFakeExitTool implements ToolInterface
{
    public static int $executionCount = 0;
    public function __construct() {}
    public function getMenuLabel(ToolContext $context): string { return 'Exit'; }
    public function execute(ToolIOInterface $io): ?ToolContext
    {
        self::$executionCount++;
        return ExploreSession::exit();
    }
}

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'session-increment', group: 'Test')]
final class SessionFakeIncrementTool implements ToolInterface
{
    public static int $executionCount = 0;
    public function __construct(private readonly SessionFakeCounter $counter) {}
    public function getMenuLabel(ToolContext $context): string { return 'Increment'; }
    public function execute(ToolIOInterface $io): ?ToolContext
    {
        self::$executionCount++;
        return ToolContext::empty()->with('counter', new SessionFakeCounter($this->counter->value + 1));
    }
}

final class SessionScriptedToolIO implements ToolIOInterface
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
    public function writeInfo(string $message): void {}
    public function writeNote(string $message): void {}
    public function chooseFromTable(string $question, array $headers, array $rows): string { return (string)array_key_first($rows); }
    public function ask(string $question, ?callable $autocomplete = null): string { return ''; }
    public function confirm(string $question, bool $default = false): bool { return $default; }
    public function progress(string $label, int $total, \Closure $callback): void { $callback(static function(): void {}); }
    public function chooseMultiple(string $question, array $choices, array $default = []): array { return $default; }
    public function task(string $label, \Closure $callback): void { $callback(); }

    public function chooseFromMenu(ToolMenu $menu): string
    {
        $this->renderedChoiceLabels = array_map(fn($item) => $item->label, $menu->items);
        return array_shift($this->choices) ?? $menu->available()[0]->shortName ?? '';
    }
}
