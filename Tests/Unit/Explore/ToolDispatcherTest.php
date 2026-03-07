<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore;

use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use PHPUnit\Framework\TestCase;

class ToolDispatcherTest extends TestCase
{
    private ToolContextRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolContextRegistry();
        $this->registry->register(
            name: 'node',
            type: FakeNodeAggregateId::class,
            alias: 'n',
            fromString: fn(string $s) => new FakeNodeAggregateId($s),
            toString: fn(FakeNodeAggregateId $v) => $v->value,
        );
    }

    public function test_tool_with_no_required_params_is_always_available(): void
    {
        $tool = new AlwaysAvailableTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool]);

        $available = $dispatcher->availableTools(ToolContext::empty());

        self::assertContains($tool, $available);
    }

    public function test_tool_requiring_node_is_unavailable_without_it(): void
    {
        $tool = new RequiresNodeTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool]);

        $available = $dispatcher->availableTools(ToolContext::empty());

        self::assertNotContains($tool, $available);
    }

    public function test_tool_requiring_node_is_available_with_it(): void
    {
        $tool = new RequiresNodeTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool]);
        $ctx = ToolContext::empty()->with('node', new FakeNodeAggregateId('abc'));

        $available = $dispatcher->availableTools($ctx);

        self::assertContains($tool, $available);
    }

    public function test_tool_with_optional_param_is_always_available(): void
    {
        $tool = new OptionalNodeTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool]);

        self::assertContains($tool, $dispatcher->availableTools(ToolContext::empty()));
        $ctx = ToolContext::empty()->with('node', new FakeNodeAggregateId('abc'));
        self::assertContains($tool, $dispatcher->availableTools($ctx));
    }

    public function test_execute_passes_context_values_to_tool(): void
    {
        $tool = new RequiresNodeTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool]);
        $io = new FakeToolIO();
        $ctx = ToolContext::empty()->with('node', new FakeNodeAggregateId('abc'));

        $dispatcher->execute($tool, $ctx, $io);

        self::assertSame('abc', $tool->receivedNode?->value);
    }

    public function test_execute_returns_updated_context(): void
    {
        $tool = new ContextUpdatingTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool]);
        $io = new FakeToolIO();
        $ctx = ToolContext::empty();

        $newCtx = $dispatcher->execute($tool, $ctx, $io);

        self::assertNotNull($newCtx);
        self::assertTrue($newCtx->has('node'));
    }

    public function test_boot_validation_rejects_unrecognised_parameter_type(): void
    {
        $this->expectException(\LogicException::class);

        new ToolDispatcher($this->registry, [new UnknownParamTool()]);
    }

    public function test_tool_context_parameter_is_always_accepted_and_injected(): void
    {
        $tool = new ContextPassthroughTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool]);
        $io = new FakeToolIO();
        $ctx = ToolContext::empty()->with('node', new FakeNodeAggregateId('xyz'));

        $dispatcher->execute($tool, $ctx, $io);

        self::assertSame($ctx, $tool->receivedContext);
    }

    // --- Derived resolvers ---

    public function test_derived_type_passes_validation(): void
    {
        $this->expectNotToPerformAssertions();
        new ToolDispatcher($this->registry, [new DerivedParamTool()], [
            FakeDerivedService::class => fn(ToolContext $ctx) => new FakeDerivedService('resolved'),
        ]);
    }

    public function test_derived_type_tool_available_when_resolver_returns_value(): void
    {
        $tool = new DerivedParamTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool], [
            FakeDerivedService::class => fn(ToolContext $ctx) => new FakeDerivedService('resolved'),
        ]);

        self::assertContains($tool, $dispatcher->availableTools(ToolContext::empty()));
    }

    public function test_derived_type_tool_unavailable_when_resolver_returns_null(): void
    {
        $tool = new DerivedParamTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool], [
            FakeDerivedService::class => fn(ToolContext $ctx) => null,
        ]);

        self::assertNotContains($tool, $dispatcher->availableTools(ToolContext::empty()));
    }

    public function test_derived_type_is_injected_into_execute(): void
    {
        $tool = new DerivedParamTool();
        $dispatcher = new ToolDispatcher($this->registry, [$tool], [
            FakeDerivedService::class => fn(ToolContext $ctx) => new FakeDerivedService('injected'),
        ]);
        $io = new FakeToolIO();

        $dispatcher->execute($tool, ToolContext::empty(), $io);

        self::assertSame('injected', $tool->receivedService?->label);
    }
}

// --- Fake value objects ---

final class FakeNodeAggregateId
{
    public function __construct(public readonly string $value) {}
}

// --- Fake tools ---

final class AlwaysAvailableTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string { return 'Always available'; }
    public function execute(ToolIOInterface $io): ?ToolContext { return null; }
}

final class RequiresNodeTool implements ToolInterface
{
    public ?FakeNodeAggregateId $receivedNode = null;

    public function getMenuLabel(ToolContext $context): string { return 'Requires node'; }
    public function execute(ToolIOInterface $io, FakeNodeAggregateId $node): ?ToolContext
    {
        $this->receivedNode = $node;
        return null;
    }
}

final class OptionalNodeTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string { return 'Optional node'; }
    public function execute(ToolIOInterface $io, ?FakeNodeAggregateId $node = null): ?ToolContext { return null; }
}

final class ContextUpdatingTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string { return 'Updates context'; }
    public function execute(ToolIOInterface $io): ?ToolContext
    {
        return ToolContext::empty()->with('node', new FakeNodeAggregateId('new'));
    }
}

final class ContextPassthroughTool implements ToolInterface
{
    public ?ToolContext $receivedContext = null;
    public function getMenuLabel(ToolContext $context): string { return 'Context passthrough'; }
    public function execute(ToolIOInterface $io, ToolContext $context): ?ToolContext
    {
        $this->receivedContext = $context;
        return null;
    }
}

final class UnknownParamTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string { return 'Unknown param'; }
    public function execute(ToolIOInterface $io, \DateTimeImmutable $unknown): ?ToolContext { return null; }
}

final class DerivedParamTool implements ToolInterface
{
    public ?FakeDerivedService $receivedService = null;
    public function getMenuLabel(ToolContext $context): string { return 'Derived param'; }
    public function execute(ToolIOInterface $io, FakeDerivedService $service): ?ToolContext
    {
        $this->receivedService = $service;
        return null;
    }
}

final class FakeDerivedService
{
    public function __construct(public readonly string $label) {}
}

final class FakeToolIO implements ToolIOInterface
{
    public function writeTable(array $headers, array $rows): void {}
    public function writeKeyValue(array $pairs): void {}
    public function writeLine(string $text = ''): void {}
    public function writeError(string $message): void {}
    public function ask(string $question, ?callable $autocomplete = null): string { return ''; }
    public function choose(string $question, array $choices): string { return array_key_first($choices); }
}
