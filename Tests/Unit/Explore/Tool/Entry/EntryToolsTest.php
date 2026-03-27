<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore\Tool\Entry;

use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\Tool\Entry\SetNodeByUuidTool;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolMenu;
use PHPUnit\Framework\TestCase;

class EntryToolsTest extends TestCase
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

    public function test_set_node_asks_for_uuid_and_sets_node_in_context(): void
    {
        $tool = new SetNodeByUuidTool();
        $io = new AskingToolIO('abc-123');

        $result = $tool->execute($io, ToolContext::create($this->registry));

        self::assertNotNull($result);
        $node = $result->get('node');
        self::assertInstanceOf(FakeNodeId::class, $node);
        self::assertSame('abc-123', $node->value);
    }

    public function test_set_node_preserves_existing_context_slots(): void
    {
        $tool = new SetNodeByUuidTool();
        $io = new AskingToolIO('new-uuid');
        $ctx = ToolContext::create($this->registry)->with('other', new \stdClass());

        $result = $tool->execute($io, $ctx);

        self::assertNotNull($result);
        self::assertTrue($result->has('other'));
        self::assertTrue($result->has('node'));
    }
}

// --- Fakes ---

final class FakeNodeId
{
    public function __construct(public readonly string $value) {}
}

final class AskingToolIO implements ToolIOInterface
{
    public function __construct(private readonly string $answer) {}
    public function writeTable(array $headers, array $rows): void {}
    public function writeKeyValue(array $pairs): void {}
    public function writeLine(string $text = ''): void {}
    public function writeError(string $message): void {}
    public function writeInfo(string $message): void {}
    public function writeNote(string $message): void {}
    public function ask(string $question, ?callable $autocomplete = null): string { return $this->answer; }
    public function choose(string $question, array $choices): string { return (string)array_key_first($choices); }
    public function chooseMultiple(string $question, array $choices, array $default = []): array { return $default; }
    public function chooseFromMenu(ToolMenu $menu): string { return $menu->available()[0]->shortName ?? ''; }
}
