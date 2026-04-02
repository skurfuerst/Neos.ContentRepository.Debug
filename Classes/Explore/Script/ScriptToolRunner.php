<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Script;

use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolBuilder;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\ContentRepository\Debug\Explore\ToolMenu;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @api Provides the `$tools->run(SomeTool::class)` API for use in debug scripts.
 *
 *      Wraps {@see ToolBuilder} + {@see ToolDispatcher} and maintains a mutable {@see ToolContext}
 *      across calls — a tool that updates context (e.g. sets crId) propagates that change to subsequent calls.
 *
 *      Implements {@see ToolIOInterface} directly: output goes to stdout; interactive prompts throw immediately.
 *      Tools that require user input (ask/confirm) cannot be run in scripts without first providing
 *      all required context values via {@see withContext()}.
 *
 * Usage in a debug script (injected via `./flow cr:debugScript`):
 * ```php
 * $tools->run(StatusTool::class);
 * $tools->withContext('cr', 'default')->run(PruneRemovedContentStreamsTool::class);
 * ```
 *
 * @see ToolContext for managing session state between tool calls.
 */
final class ScriptToolRunner implements ToolIOInterface
{
    private ToolContext $context;

    public function __construct(
        private readonly ToolDispatcher $dispatcher,
        private readonly ToolBuilder $builder,
        private readonly array $derivedResolvers,
        ToolContext $initialContext,
    ) {
        $this->context = $initialContext;
    }

    /**
     * Return a new instance with a pre-set context value — useful to seed workspace, node, etc.
     * before the first run() call. Does not mutate the current instance.
     */
    public function withContext(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->context = $this->context->withFromString($name, $value);
        return $clone;
    }

    /**
     * Fire the session bootstrap notifications — same as {@see ExploreSession::run()} does before
     * the first menu. Triggers {@see \Neos\ContentRepository\Debug\Explore\Tool\WithContextChangeInterface}
     * hooks (e.g. subscription health warning, dynamic CR auto-registration).
     *
     * Optional: call this if you want the same proactive warnings a live session would show.
     */
    public function bootstrap(): void
    {
        $this->dispatcher->notifyContextChange(ToolContext::empty(), $this->context, $this);
    }

    /**
     * Execute a tool by its class name. Output is written to stdout.
     *
     * @param class-string $toolClass
     * @throws \InvalidArgumentException if the tool cannot be built (missing required context deps).
     * @throws \LogicException if the tool returns {@see ExploreSession::exit()} — exit tools cannot be used in scripts.
     * @throws \RuntimeException if the tool calls an interactive prompt (ask/confirm/choose*).
     */
    public function run(string $toolClass): static
    {
        $tool = $this->builder->build($toolClass, $this->context, $this->derivedResolvers);

        if ($tool === null) {
            throw new \InvalidArgumentException(sprintf(
                'Tool "%s" cannot be built for the current context (missing required deps).',
                $toolClass,
            ));
        }

        $result = $tool->execute($this);

        if ($result === ExploreSession::exit()) {
            throw new \LogicException(sprintf(
                'Tool "%s" signalled session exit — exit tools cannot be used in scripts.',
                $toolClass,
            ));
        }

        if ($result !== null) {
            $old = $this->context;
            $this->context = $result;
            $this->dispatcher->notifyContextChange($old, $this->context, $this);
        }

        return $this;
    }

    // ── ToolIOInterface — output methods write to stdout ─────────────────────

    public function writeLine(string $text = ''): void
    {
        echo $text . "\n";
    }

    public function writeError(string $message): void
    {
        echo 'ERROR: ' . $message . "\n";
    }

    public function writeInfo(string $message): void
    {
        echo $message . "\n";
    }

    public function writeNote(string $message): void
    {
        echo $message . "\n";
    }

    public function writeTable(array $headers, array $rows): void
    {
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
        echo $output->fetch();
    }

    public function writeKeyValue(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            echo $key . ': ' . $value . "\n";
        }
    }

    public function progress(string $label, int $total, \Closure $callback): void
    {
        echo $label . "...\n";
        $callback(static function (): void {});
        echo "Done.\n";
    }

    public function task(string $label, \Closure $callback): void
    {
        echo $label . "...\n";
        $callback(static function (string $line): void { echo $line . "\n"; });
    }

    // ── ToolIOInterface — interactive methods: not supported in scripts ────────

    public function ask(string $question, ?callable $autocomplete = null): string
    {
        throw new \RuntimeException(
            'ScriptToolRunner does not support interactive prompts. ' .
            'Provide all required values via withContext() before calling run().',
        );
    }

    public function confirm(string $question, bool $default = false): bool
    {
        throw new \RuntimeException(
            'ScriptToolRunner does not support interactive prompts. ' .
            'Provide all required values via withContext() before calling run().',
        );
    }

    public function chooseMultiple(string $question, array $choices, array $default = []): array
    {
        throw new \RuntimeException(
            'ScriptToolRunner does not support interactive prompts. ' .
            'Provide all required values via withContext() before calling run().',
        );
    }

    public function chooseFromTable(string $question, array $headers, array $rows): string
    {
        throw new \RuntimeException(
            'ScriptToolRunner does not support interactive prompts. ' .
            'Provide all required values via withContext() before calling run().',
        );
    }

    public function chooseFromMenu(ToolMenu $menu): string
    {
        throw new \RuntimeException(
            'ScriptToolRunner does not support interactive prompts. ' .
            'Provide all required values via withContext() before calling run().',
        );
    }
}
