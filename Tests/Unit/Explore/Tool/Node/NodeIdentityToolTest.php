<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\Node\NodeIdentityTool;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use PHPUnit\Framework\TestCase;

class NodeIdentityToolTest extends TestCase
{
    public function test_label(): void
    {
        $tool = new NodeIdentityTool();
        self::assertSame('Node: identity', $tool->getMenuLabel(ToolContext::empty()));
    }

    public function test_writes_error_when_node_not_found(): void
    {
        $tool = new NodeIdentityTool();
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $contentGraph->method('findNodeAggregateById')->willReturn(null);

        $io = new SpyIO();
        $result = $tool->execute($io, $contentGraph, NodeAggregateId::fromString('missing-id'));

        self::assertNull($result);
        self::assertStringContainsString('not found', $io->lastError);
    }

    public function test_returns_null_context(): void
    {
        $tool = new NodeIdentityTool();
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $contentGraph->method('findNodeAggregateById')->willReturn(null);

        $io = new SpyIO();
        $result = $tool->execute($io, $contentGraph, NodeAggregateId::fromString('any'));

        self::assertNull($result, 'NodeIdentityTool is read-only and should never modify context');
    }
}

final class SpyIO implements ToolIOInterface
{
    public string $lastError = '';
    /** @var array<string, string> */
    public array $lastKeyValue = [];

    public function writeTable(array $headers, array $rows): void {}
    public function writeKeyValue(array $pairs): void { $this->lastKeyValue = $pairs; }
    public function writeLine(string $text = ''): void {}
    public function writeError(string $message): void { $this->lastError = $message; }
    public function ask(string $question, ?callable $autocomplete = null): string { return ''; }
    public function choose(string $question, array $choices): string { return (string)array_key_first($choices); }
}
