<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;

/**
 * @internal Constructs tool instances per-dispatch by reflecting on __construct() and resolving each parameter
 *           from three sources:
 *           1. {@see ToolContext} — always injected for ToolContext params
 *           2. Registered context types (via {@see ToolContextRegistry}) — resolved from the current ToolContext
 *           3. Derived types (via $derivedResolvers closures) — computed from context on demand
 *           4. Everything else — resolved via {@see ObjectManagerInterface} (framework services, singletons, etc.)
 *
 *           Uses {@see ReflectionService} (not PHP's native reflection) to inspect constructor parameters so that
 *           Flow-generated proxy classes are transparent: the service reflects on the original class definition.
 *           Availability is determined by whether all required (non-nullable, non-default) constructor params
 *           can be resolved. Silently returns null when any required param is missing.
 *
 * @see ToolDispatcher where ToolBuilder is used to produce tool instances per-invocation.
 */
#[Flow\Scope('singleton')]
final class ToolBuilder
{
    public function __construct(
        private readonly ToolContextRegistry $registry,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ReflectionService $reflectionService,
    ) {}

    /**
     * Build a tool instance with all constructor deps resolved from the given context.
     * Returns null if any required (non-nullable, non-default) constructor dep cannot be resolved.
     *
     * @param class-string<ToolInterface> $toolClass
     * @param array<class-string, \Closure(ToolContext): ?object> $derivedResolvers
     */
    public function build(string $toolClass, ToolContext $context, array $derivedResolvers): ?ToolInterface
    {
        $args = $this->resolveConstructorArgs($toolClass, $context, $derivedResolvers);
        if ($args === null) {
            return null;
        }
        return new $toolClass(...$args);
    }

    /**
     * Returns true if the tool can be built (all required constructor deps can be resolved).
     *
     * @param class-string<ToolInterface> $toolClass
     * @param array<class-string, \Closure(ToolContext): ?object> $derivedResolvers
     */
    public function canBuild(string $toolClass, ToolContext $context, array $derivedResolvers): bool
    {
        return $this->resolveConstructorArgs($toolClass, $context, $derivedResolvers) !== null;
    }

    /**
     * Instantiate a tool WITHOUT calling the constructor — safe only for calling {@see ToolInterface::getMenuLabel()},
     * which must never access $this properties (only $context). Used exclusively by {@see ToolDispatcher::buildMenu()}.
     *
     * @param class-string<ToolInterface> $toolClass
     */
    public function buildForLabel(string $toolClass): ToolInterface
    {
        $ref = new \ReflectionClass($toolClass);
        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * Returns true if the tool's constructor declares any derived-type parameter.
     * Used by {@see ToolDispatcher::notifyContextChange()} to order tools into two passes
     * (framework/context-only tools first, derived-type tools second).
     *
     * @param class-string<ToolInterface> $toolClass
     * @param array<class-string, \Closure(ToolContext): ?object> $derivedResolvers
     */
    public function constructorHasDerivedParam(string $toolClass, array $derivedResolvers): bool
    {
        foreach ($this->getConstructorParams($toolClass) as $paramData) {
            if (isset($derivedResolvers[$paramData['type']])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns missing required context-type names for display in the tool menu (greyed-out items).
     *
     * @param class-string<ToolInterface> $toolClass
     * @param array<class-string, \Closure(ToolContext): ?object> $derivedResolvers
     * @param array<class-string, list<class-string>> $derivedDependencies
     * @return list<string>
     */
    public function missingContextTypes(
        string $toolClass,
        ToolContext $context,
        array $derivedResolvers,
        array $derivedDependencies,
    ): array {
        $missing = [];
        foreach ($this->getConstructorParams($toolClass) as $paramData) {
            if ($paramData['optional'] || $paramData['allowsNull']) {
                continue;
            }
            $typeName = $paramData['type'];
            if ($typeName === ToolContext::class) {
                continue;
            }
            if ($this->registry->getByType($typeName) !== null) {
                if (!$context->hasByType($typeName)) {
                    $descriptor = $this->registry->getByType($typeName);
                    $missing[] = $descriptor?->name ?? $typeName;
                }
                continue;
            }
            if (isset($derivedResolvers[$typeName])) {
                try {
                    $resolved = ($derivedResolvers[$typeName])($context);
                } catch (\Throwable) {
                    $resolved = null;
                }
                if ($resolved === null) {
                    foreach ($derivedDependencies[$typeName] ?? [] as $depType) {
                        if (!$context->hasByType($depType)) {
                            $descriptor = $this->registry->getByType($depType);
                            $missing[] = $descriptor?->name ?? $depType;
                        }
                    }
                }
            }
        }
        return array_values(array_unique($missing));
    }

    /**
     * Returns all required registered-context-type names declared in the constructor — regardless of
     * whether they are currently in context. Used for badge display in {@see ToolDispatcher::buildMenu()}.
     *
     * @param class-string<ToolInterface> $toolClass
     * @return list<string>
     */
    public function requiredContextTypes(string $toolClass): array
    {
        $required = [];
        foreach ($this->getConstructorParams($toolClass) as $paramData) {
            if ($paramData['optional'] || $paramData['allowsNull']) {
                continue;
            }
            $descriptor = $this->registry->getByType($paramData['type']);
            if ($descriptor !== null) {
                $required[] = $descriptor->name;
            }
        }
        return $required;
    }

    /**
     * Resolves constructor arguments for $toolClass from the given context and resolvers.
     * Returns null if any required (non-nullable, non-default) parameter cannot be resolved.
     *
     * Resolution order per parameter:
     *   1. ToolContext → inject $context directly
     *   2. Registered context type → resolve from $context via ToolContextRegistry
     *   3. Derived type → resolve via $derivedResolvers closure
     *   4. Everything else → resolve via ObjectManagerInterface (singletons, services)
     *
     * @param class-string<ToolInterface> $toolClass
     * @param array<class-string, \Closure(ToolContext): ?object> $derivedResolvers
     * @return list<mixed>|null null when a required param cannot be resolved
     */
    private function resolveConstructorArgs(string $toolClass, ToolContext $context, array $derivedResolvers): ?array
    {
        $params = $this->getConstructorParams($toolClass);
        if ($params === []) {
            return [];
        }

        $args = [];
        foreach ($params as $paramData) {
            $typeName = $paramData['type'];
            $allowsNull = $paramData['allowsNull'];
            $optional = $paramData['optional'];

            // 1. ToolContext — always inject current context
            if ($typeName === ToolContext::class) {
                $args[] = $context;
                continue;
            }

            // 2. Registered context type
            if ($this->registry->getByType($typeName) !== null) {
                $resolved = $context->getByType($typeName);
                if ($resolved === null && !$optional && !$allowsNull) {
                    return null; // required context value absent
                }
                $args[] = $resolved;
                continue;
            }

            // 3. Derived type
            if (isset($derivedResolvers[$typeName])) {
                try {
                    $resolved = ($derivedResolvers[$typeName])($context);
                } catch (\Throwable) {
                    $resolved = null;
                }
                if ($resolved === null && !$optional && !$allowsNull) {
                    return null; // required derived value unavailable
                }
                $args[] = $resolved;
                continue;
            }

            // 4. Framework service via ObjectManager
            try {
                $args[] = $this->objectManager->get($typeName);
            } catch (\Throwable) {
                if (!$optional && !$allowsNull) {
                    return null;
                }
                $args[] = $optional ? ($paramData['defaultValue'] ?? null) : null;
            }
        }

        return $args;
    }

    /**
     * Returns constructor parameters sorted by position, using {@see ReflectionService} to reflect the
     * original class definition (transparent to Flow-generated proxies).
     *
     * Falls back to native PHP reflection for classes not in Flow's class schema
     * (e.g. inline test fakes, non-managed classes), which are not proxied and can be reflected directly.
     *
     * @param class-string $toolClass
     * @return list<array{type: string, allowsNull: bool, optional: bool, defaultValue: mixed}>
     */
    private function getConstructorParams(string $toolClass): array
    {
        // ReflectionService is aware of the original class before proxy generation
        $params = $this->reflectionService->getMethodParameters($toolClass, '__construct');
        if ($params !== []) {
            uasort($params, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
            return array_values($params);
        }

        // Fallback: native reflection for non-Flow-managed classes (test fakes, etc.)
        $ref = new \ReflectionClass($toolClass);
        $constructor = $ref->getConstructor();
        if ($constructor === null) {
            return [];
        }
        $result = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
            $result[] = [
                'position'     => $param->getPosition(),
                'optional'     => $param->isOptional(),
                'type'         => $typeName,
                'allowsNull'   => $param->allowsNull(),
                'defaultValue' => $param->isOptional() && $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }
        return $result;
    }
}
