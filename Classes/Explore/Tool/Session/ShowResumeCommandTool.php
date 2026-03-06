<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Session;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextSerializer;

/**
 * @internal Always-available tool that prints the CLI invocation needed to resume the current session.
 *           Uses {@see ToolContextSerializer} to serialise all context slots into --name=value flags.
 */
final class ShowResumeCommandTool implements ToolInterface
{
    public function __construct(private readonly ToolContextSerializer $serializer) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Show resume command';
    }

    public function execute(ToolIOInterface $io, ToolContext $context): ?ToolContext
    {
        $parts = ['./flow cr:explore'];
        foreach ($this->serializer->serialize($context) as $name => $value) {
            $parts[] = "--{$name}={$value}";
        }
        $io->writeLine(implode(' ', $parts));
        return null;
    }
}
