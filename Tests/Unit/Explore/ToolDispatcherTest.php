<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore;

use Neos\ContentRepository\Debug\Explore\ToolBuilder;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolMenu;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use PHPUnit\Framework\TestCase;

class ToolDispatcherTest extends TestCase
{
    private ToolContextRegistry $registry;
    private ToolBuilder $builder;

    protected function setUp(): void
    {
        $this->registry = new ToolContextRegistry();
        $this->registry->register(
            name: 'node',
            type: DispFakeNodeId::class,
            alias: 'n',
            fromString: fn(string $s) => new DispFakeNodeId($s),
            toString: fn(DispFakeNodeId $v) => $v->value,
        );
        $this->builder = $this->makeBuilder($this->registry);
    }

    public function test_tool_with_no_required_params_is_always_available(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispAlwaysAvailableTool::class]);

        $menu = $dispatcher->buildMenu(ToolContext::empty());

        self::assertTrue($menu->available() !== []);
        self::assertSame(DispAlwaysAvailableTool::class, $menu->available()[0]->toolClass);
    }

    public function test_tool_requiring_node_is_unavailable_without_it(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispRequiresNodeTool::class]);

        $menu = $dispatcher->buildMenu(ToolContext::empty());

        self::assertSame([], $menu->available());
    }

    public function test_tool_requiring_node_is_available_with_it(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispRequiresNodeTool::class]);
        $ctx = ToolContext::empty()->with('node', new DispFakeNodeId('abc'));

        $menu = $dispatcher->buildMenu($ctx);

        self::assertNotSame([], $menu->available());
        self::assertSame(DispRequiresNodeTool::class, $menu->available()[0]->toolClass);
    }

    public function test_tool_with_optional_param_is_always_available(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispOptionalNodeTool::class]);

        self::assertNotSame([], $dispatcher->buildMenu(ToolContext::empty())->available());
        $ctx = ToolContext::empty()->with('node', new DispFakeNodeId('abc'));
        self::assertNotSame([], $dispatcher->buildMenu($ctx)->available());
    }

    public function test_execute_runs_tool_and_returns_result(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispContextUpdatingTool::class]);
        $io = new DispFakeToolIO();
        $ctx = ToolContext::empty();

        $newCtx = $dispatcher->execute(DispContextUpdatingTool::class, $ctx, $io);

        self::assertNotNull($newCtx);
        self::assertTrue($newCtx->has('node'));
    }

    public function test_execute_passes_context_values_via_constructor(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispNodeEchoTool::class]);
        $io = new DispFakeToolIO();
        $ctx = ToolContext::empty()->with('node', new DispFakeNodeId('abc'));

        $dispatcher->execute(DispNodeEchoTool::class, $ctx, $io);

        self::assertStringContainsString('abc', $io->output);
    }

    public function test_execute_injects_tool_context_via_constructor(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispContextPassthroughTool::class]);
        $io = new DispFakeToolIO();
        $ctx = ToolContext::empty()->with('node', new DispFakeNodeId('xyz'));

        $result = $dispatcher->execute(DispContextPassthroughTool::class, $ctx, $io);

        // Tool returns the context it was given — verify it round-trips
        self::assertSame($ctx, $result);
    }

    // --- Derived resolvers ---

    public function test_derived_type_passes_validation(): void
    {
        $this->expectNotToPerformAssertions();
        new ToolDispatcher($this->registry, $this->builder, [DispDerivedParamTool::class], [
            DispFakeDerivedService::class => fn(ToolContext $ctx) => new DispFakeDerivedService('resolved'),
        ]);
    }

    public function test_derived_type_tool_available_when_resolver_returns_value(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispDerivedParamTool::class], [
            DispFakeDerivedService::class => fn(ToolContext $ctx) => new DispFakeDerivedService('resolved'),
        ]);

        self::assertNotSame([], $dispatcher->buildMenu(ToolContext::empty())->available());
    }

    public function test_derived_type_tool_unavailable_when_resolver_returns_null(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispDerivedParamTool::class], [
            DispFakeDerivedService::class => fn(ToolContext $ctx) => null,
        ]);

        self::assertSame([], $dispatcher->buildMenu(ToolContext::empty())->available());
    }

    public function test_unavailable_derived_type_reports_missing_underlying_context_types(): void
    {
        $this->registry->register(
            name: 'workspace',
            type: DispFakeWorkspaceName::class,
            alias: 'ws',
            fromString: fn(string $s) => new DispFakeWorkspaceName($s),
            toString: fn(DispFakeWorkspaceName $v) => $v->value,
        );
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispDerivedParamTool::class], derivedResolvers: [
            DispFakeDerivedService::class => fn(ToolContext $ctx) => null,
        ], derivedDependencies: [
            DispFakeDerivedService::class => [DispFakeNodeId::class, DispFakeWorkspaceName::class],
        ]);

        $ctx = ToolContext::empty()->with('node', new DispFakeNodeId('abc'));
        $menu = $dispatcher->buildMenu($ctx);

        $item = $menu->findByShortName('disp-derived-param');
        self::assertNotNull($item);
        self::assertFalse($item->available);
        self::assertContains('workspace', $item->missingContextTypes);
        self::assertNotContains('node', $item->missingContextTypes);
    }

    public function test_derived_type_is_injected_via_constructor(): void
    {
        $dispatcher = new ToolDispatcher($this->registry, $this->builder, [DispDerivedParamTool::class], [
            DispFakeDerivedService::class => fn(ToolContext $ctx) => new DispFakeDerivedService('injected'),
        ]);
        $io = new DispFakeToolIO();

        $dispatcher->execute(DispDerivedParamTool::class, ToolContext::empty(), $io);

        self::assertStringContainsString('injected', $io->output);
    }

    // --- Helpers ---

    private function makeBuilder(ToolContextRegistry $registry): ToolBuilder
    {
        // ReflectionService returns empty → ToolBuilder falls back to native PHP reflection
        $reflectionService = $this->createStub(ReflectionService::class);
        $reflectionService->method('getMethodParameters')->willReturn([]);

        // ObjectManager throws → tests fail if a fake accidentally requires ObjectManager resolution
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willThrowException(new \RuntimeException('ObjectManager not available in unit tests'));

        return new ToolBuilder($registry, $objectManager, $reflectionService);
    }
}

// --- Fake value objects ---

final class DispFakeNodeId
{
    public function __construct(public readonly string $value) {}
}

final class DispFakeWorkspaceName
{
    public function __construct(public readonly string $value) {}
}

// --- Fake tools (constructor injection) ---

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'disp-always', group: 'Test')]
final class DispAlwaysAvailableTool implements ToolInterface
{
    public function __construct() {}
    public function getMenuLabel(ToolContext $context): string { return 'Always available'; }
    public function execute(ToolIOInterface $io): ?ToolContext { return null; }
}

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'disp-requires-node', group: 'Test')]
final class DispRequiresNodeTool implements ToolInterface
{
    public function __construct(private readonly DispFakeNodeId $node) {}
    public function getMenuLabel(ToolContext $context): string { return 'Requires node'; }
    public function execute(ToolIOInterface $io): ?ToolContext { return null; }
}

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'disp-optional-node', group: 'Test')]
final class DispOptionalNodeTool implements ToolInterface
{
    public function __construct(private readonly ?DispFakeNodeId $node = null) {}
    public function getMenuLabel(ToolContext $context): string { return 'Optional node'; }
    public function execute(ToolIOInterface $io): ?ToolContext { return null; }
}

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'disp-context-updating', group: 'Test')]
final class DispContextUpdatingTool implements ToolInterface
{
    public function __construct() {}
    public function getMenuLabel(ToolContext $context): string { return 'Updates context'; }
    public function execute(ToolIOInterface $io): ?ToolContext
    {
        return ToolContext::empty()->with('node', new DispFakeNodeId('new'));
    }
}

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'disp-node-echo', group: 'Test')]
final class DispNodeEchoTool implements ToolInterface
{
    public function __construct(private readonly DispFakeNodeId $node) {}
    public function getMenuLabel(ToolContext $context): string { return 'Node echo'; }
    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $io->writeLine($this->node->value);
        return null;
    }
}

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'disp-context-passthrough', group: 'Test')]
final class DispContextPassthroughTool implements ToolInterface
{
    public function __construct(private readonly ToolContext $context) {}
    public function getMenuLabel(ToolContext $context): string { return 'Context passthrough'; }
    public function execute(ToolIOInterface $io): ?ToolContext { return $this->context; }
}

#[\Neos\ContentRepository\Debug\Explore\Tool\ToolMeta(shortName: 'disp-derived-param', group: 'Test')]
final class DispDerivedParamTool implements ToolInterface
{
    public function __construct(private readonly DispFakeDerivedService $service) {}
    public function getMenuLabel(ToolContext $context): string { return 'Derived param'; }
    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $io->writeLine($this->service->label);
        return null;
    }
}

final class DispFakeDerivedService
{
    public function __construct(public readonly string $label) {}
}

final class DispFakeToolIO implements ToolIOInterface
{
    public string $output = '';
    public function writeTable(array $headers, array $rows): void {}
    public function writeKeyValue(array $pairs): void {}
    public function writeLine(string $text = ''): void { $this->output .= $text . "\n"; }
    public function writeError(string $message): void { $this->output .= 'ERROR: ' . $message . "\n"; }
    public function writeInfo(string $message): void { $this->output .= $message . "\n"; }
    public function writeNote(string $message): void { $this->output .= $message . "\n"; }
    public function chooseFromTable(string $question, array $headers, array $rows): string { return (string)array_key_first($rows); }
    public function ask(string $question, ?callable $autocomplete = null): string { return ''; }
    public function confirm(string $question, bool $default = false): bool { return $default; }
    public function progress(string $label, int $total, \Closure $callback): void { $callback(static function(): void {}); }
    public function chooseMultiple(string $question, array $choices, array $default = []): array { return $default; }
    public function chooseFromMenu(ToolMenu $menu): string { return $menu->available()[0]->shortName ?? ''; }
    public function task(string $label, \Closure $callback): void { $callback(); }
}
