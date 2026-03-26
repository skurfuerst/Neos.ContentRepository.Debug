<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
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
     * @param array<class-string, \Closure(ToolContext): ?object> $derivedResolvers Lazy resolvers for types
     *        derived from context (e.g. ContentRepository from ContentRepositoryId). Return null = unavailable.
     * @throws \LogicException if any tool's execute() declares a parameter type that is neither
     *                         {@see ToolIOInterface} nor a type registered in {@see ToolContextRegistry}.
     */
    public function __construct(
        private readonly ToolContextRegistry $registry,
        iterable $tools,
        private readonly array $derivedResolvers = [],
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

    /**
     * Build a {@see ToolMenu} containing ALL tools (available + unavailable) for the current context,
     * grouped and sorted for display. Session-group tools appear last; within each group available
     * tools come before unavailable ones.
     *
     * @param string $contextDisplay Optional dimmed line shown inside the selector widget (e.g. resume command).
     */
    public function buildMenu(ToolContext $context, string $contextDisplay = ''): ToolMenu
    {
        // Collect items per group, Session group deferred to end
        /** @var array<string, list<ToolMenuItem>> $byGroup */
        $byGroup = [];
        /** @var list<ToolMenuItem> $sessionItems */
        $sessionItems = [];

        foreach ($this->tools as $tool) {
            $meta = $this->resolveToolMeta($tool);
            $available = $this->isAvailable($tool, $context);
            $missing = $available ? [] : $this->missingContextTypes($tool, $context);
            $required = $this->requiredContextTypes($tool);

            $item = new ToolMenuItem(
                shortName: $meta->shortName,
                label: $tool->getMenuLabel($context),
                group: $meta->group,
                available: $available,
                tool: $tool,
                missingContextTypes: $missing,
                requiredContextTypes: $required,
            );

            if ($meta->group === 'Session') {
                $sessionItems[] = $item;
            } else {
                $byGroup[$meta->group][] = $item;
            }
        }

        // Within each group: available first, then unavailable
        $sortGroup = static function (array $items): array {
            usort($items, fn(ToolMenuItem $a, ToolMenuItem $b) => $b->available <=> $a->available);
            return $items;
        };

        $items = [];
        foreach ($byGroup as $groupItems) {
            foreach ($sortGroup($groupItems) as $item) {
                $items[] = $item;
            }
        }
        foreach ($sortGroup($sessionItems) as $item) {
            $items[] = $item;
        }

        return new ToolMenu($items, $contextDisplay);
    }

    /**
     * Read the {@see ToolMeta} attribute from the tool class, falling back to derived values:
     * - shortName: class basename without "Tool" suffix, CamelCase → kebab-case
     * - group: last namespace segment before "Tool\" sub-namespace (e.g. Tool\Node → "Node")
     */
    private function resolveToolMeta(ToolInterface $tool): ToolMeta
    {
        $ref = new \ReflectionClass($tool);
        $attrs = $ref->getAttributes(ToolMeta::class);
        if ($attrs !== []) {
            return $attrs[0]->newInstance();
        }

        // Derive shortName from class basename
        $baseName = $ref->getShortName();
        $withoutSuffix = preg_replace('/Tool$/', '', $baseName) ?? $baseName;
        // CamelCase → kebab-case
        $shortName = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $withoutSuffix));

        // Derive group from namespace segment after "Tool\"
        $ns = $ref->getNamespaceName();
        if (preg_match('/\\\\Tool\\\\([^\\\\]+)/', $ns, $m)) {
            $group = $m[1];
        } else {
            $group = 'Other';
        }

        return new ToolMeta(shortName: $shortName, group: $group);
    }

    /**
     * Collect registered context-type names that are required by the tool's execute() but absent
     * from $context. Only covers direct context types (not derived resolvers).
     *
     * @return list<string>
     */
    private function missingContextTypes(ToolInterface $tool, ToolContext $context): array
    {
        $missing = [];
        $method = new \ReflectionMethod($tool, 'execute');
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }
            $typeName = $type->getName();
            if ($this->isFrameworkInjected($typeName) || $this->isDerived($typeName)) {
                continue;
            }
            if (!$param->isOptional() && !$type->allowsNull() && !$context->hasByType($typeName)) {
                $descriptor = $this->registry->getByType($typeName);
                $missing[] = $descriptor?->name ?? $typeName;
            }
        }
        return $missing;
    }

    /**
     * Collect all registered context-type names required by the tool's execute() — regardless of
     * whether they are currently present in $context. Used for color-coded help-line badges.
     *
     * @return list<string>
     */
    private function requiredContextTypes(ToolInterface $tool): array
    {
        $required = [];
        $method = new \ReflectionMethod($tool, 'execute');
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }
            $typeName = $type->getName();
            if ($this->isFrameworkInjected($typeName) || $this->isDerived($typeName)) {
                continue;
            }
            if (!$param->isOptional() && !$type->allowsNull()) {
                $descriptor = $this->registry->getByType($typeName);
                $required[] = $descriptor?->name ?? $typeName;
            }
        }
        return $required;
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

    private function isDerived(string $typeName): bool
    {
        return isset($this->derivedResolvers[$typeName]);
    }

    private function isAvailable(ToolInterface $tool, ToolContext $context): bool
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
            if ($this->isDerived($typeName)) {
                if (!$param->isOptional() && !$type->allowsNull()) {
                    if (($this->derivedResolvers[$typeName])($context) === null) {
                        return false;
                    }
                }
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
            if ($this->isDerived($typeName)) {
                $args[] = ($this->derivedResolvers[$typeName])($context);
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
            if ($this->isFrameworkInjected($typeName) || $this->isDerived($typeName)) {
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
