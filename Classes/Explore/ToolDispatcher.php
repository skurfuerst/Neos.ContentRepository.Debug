<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
/**
 * @internal Matches tools against the current {@see ToolContext} by reflecting on execute() parameter types,
 *           then invokes the selected tool with resolved arguments — tool authors never reference this directly.
 *
 * @see ToolInterface for the execute() signature contract.
 */

final class ToolDispatcher
{
    /** @var list<ToolInterface> */
    private readonly array $tools;

    /**
     * @param iterable<ToolInterface> $tools
     * @throws \LogicException if any tool's execute() declares a parameter type that is neither
     *                         {@see ToolIOInterface} nor a type registered in {@see ToolContextRegistry}.
     */
    public function __construct(
        private readonly ToolContextRegistry $registry,
        iterable $tools,
    ) {
        $validated = [];
        foreach ($tools as $tool) {
            $this->validateTool($tool);
            $validated[] = $tool;
        }
        $this->tools = $validated;
    }

    /** @return list<ToolInterface> */
    public function availableTools(ToolContext $context): array
    {
        $available = [];
        foreach ($this->tools as $tool) {
            if ($this->isAvailable($tool, $context)) {
                $available[] = $tool;
            }
        }
        return $available;
    }

    public function execute(ToolInterface $tool, ToolContext $context, ToolIOInterface $io): ?ToolContext
    {
        $args = $this->resolveArgs($tool, $context, $io);
        return $tool->execute(...$args);
    }

    private function isFrameworkInjected(string $typeName): bool
    {
        return $typeName === ToolIOInterface::class
            || $typeName === ToolContext::class;
    }

    private function isAvailable(ToolInterface $tool, ToolContext $context): bool
    {
        $method = new \ReflectionMethod($tool, 'execute');
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }
            if ($this->isFrameworkInjected($type->getName())) {
                continue;
            }
            // Context-typed param: required (non-nullable, non-optional) → tool unavailable if absent
            if (!$param->isOptional() && !$type->allowsNull()) {
                if (!$context->hasByType($type->getName())) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return list<mixed> */
    private function resolveArgs(ToolInterface $tool, ToolContext $context, ToolIOInterface $io): array
    {
        $method = new \ReflectionMethod($tool, 'execute');
        $args = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                $args[] = null;
                continue;
            }
            $typeName = $type->getName();
            if ($typeName === ToolIOInterface::class) {
                $args[] = $io;
                continue;
            }
            if ($typeName === ToolContext::class) {
                $args[] = $context;
                continue;
            }
            $args[] = $context->getByType($typeName);
        }
        return $args;
    }

    private function validateTool(ToolInterface $tool): void
    {
        $method = new \ReflectionMethod($tool, 'execute');
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }
            $typeName = $type->getName();
            if ($this->isFrameworkInjected($typeName)) {
                continue;
            }
            if ($this->registry->getByType($typeName) === null) {
                throw new \LogicException(sprintf(
                    'Tool %s::execute() parameter $%s has type %s which is neither %s, %s, nor a registered context type.',
                    $tool::class,
                    $param->getName(),
                    $typeName,
                    ToolIOInterface::class,
                    ToolContext::class,
                ));
            }
        }
    }
}
