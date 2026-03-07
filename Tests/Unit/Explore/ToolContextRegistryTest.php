<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore;

use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\ToolContextSerializer;
use PHPUnit\Framework\TestCase;

class ToolContextRegistryTest extends TestCase
{
    private ToolContextRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolContextRegistry();
        $this->registry->register(
            name: 'node',
            type: FakeNodeId::class,
            alias: 'n',
            fromString: fn(string $s) => new FakeNodeId($s),
            toString: fn(FakeNodeId $v) => $v->value,
        );
    }

    public function test_registered_type_is_findable_by_name(): void
    {
        $descriptor = $this->registry->getByName('node');

        self::assertNotNull($descriptor);
        self::assertSame('node', $descriptor->name);
        self::assertSame(FakeNodeId::class, $descriptor->type);
        self::assertSame('n', $descriptor->alias);
    }

    public function test_registered_type_is_findable_by_type(): void
    {
        $descriptor = $this->registry->getByType(FakeNodeId::class);

        self::assertNotNull($descriptor);
        self::assertSame('node', $descriptor->name);
    }

    public function test_unknown_name_returns_null(): void
    {
        self::assertNull($this->registry->getByName('workspace'));
    }

    public function test_serializer_round_trips_context(): void
    {
        $original = new FakeNodeId('abc-123');
        $ctx = ToolContext::empty()->with('node', $original);

        $serializer = new ToolContextSerializer($this->registry);
        $strings = $serializer->serialize($ctx);
        $restored = $serializer->deserialize(ToolContext::create($this->registry), $strings);

        self::assertTrue($restored->has('node'));
        $restoredNode = $restored->get('node');
        self::assertInstanceOf(FakeNodeId::class, $restoredNode);
        self::assertSame('abc-123', $restoredNode->value);
    }
}

final class FakeNodeId
{
    public function __construct(public readonly string $value) {}
}
