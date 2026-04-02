<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\Tool\WithContextChangeInterface;

/**
 * @internal Matches tools against the current {@see ToolContext} by inspecting constructor parameter types,
 *           then builds and invokes tool instances via {@see ToolBuilder} — tool authors never reference this.
 *
 * @see ToolInterface for the execute() signature contract.
 * @see ToolBuilder for the per-dispatch construction logic.
 */
final class ToolDispatcher
{
    /** @var list<class-string<ToolInterface>> */
    private readonly array $toolClasses;

    /**
     * @param list<class-string<ToolInterface>> $toolClasses
     * @param array<class-string, \Closure(ToolContext): ?object> $derivedResolvers Lazy resolvers for derived types
     *        (e.g. ContentRepository from ContentRepositoryId). Return null = unavailable.
     * @param array<class-string, list<class-string>> $derivedDependencies Maps each derived type to the
     *        registered context types it depends on — used by {@see ToolBuilder::missingContextTypes()} to
     *        report which underlying context values are missing when the resolver returns null.
     * @throws \LogicException if any class does not implement {@see ToolInterface}.
     */
    public function __construct(
        private readonly ToolContextRegistry $registry,
        private readonly ToolBuilder $builder,
        iterable $toolClasses,
        private readonly array $derivedResolvers = [],
        private readonly array $derivedDependencies = [],
    ) {
        $validated = [];
        foreach ($toolClasses as $toolClass) {
            if (!is_a($toolClass, ToolInterface::class, true)) {
                throw new \LogicException(sprintf(
                    'Class %s does not implement %s.',
                    $toolClass,
                    ToolInterface::class,
                ));
            }
            $validated[] = $toolClass;
        }
        $this->toolClasses = $validated;
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
        /** @var array<string, list<ToolMenuItem>> $byGroup */
        $byGroup = [];
        /** @var list<ToolMenuItem> $sessionItems */
        $sessionItems = [];

        foreach ($this->toolClasses as $toolClass) {
            $meta = $this->resolveToolMeta($toolClass);
            $available = $this->builder->canBuild($toolClass, $context, $this->derivedResolvers);
            $missing = $available ? [] : $this->builder->missingContextTypes($toolClass, $context, $this->derivedResolvers, $this->derivedDependencies);
            $required = $this->builder->requiredContextTypes($toolClass);

            // Build a label-only shell (no constructor called) to call getMenuLabel($context).
            // getMenuLabel() implementations must not access $this — only $context.
            $labelShell = $this->builder->buildForLabel($toolClass);

            $item = new ToolMenuItem(
                shortName: $meta->shortName,
                label: $labelShell->getMenuLabel($context),
                group: $meta->group,
                available: $available,
                toolClass: $toolClass,
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
     * Build and execute a tool. Throws if the tool cannot be built (should be checked via canBuild first).
     *
     * @param class-string<ToolInterface> $toolClass
     */
    public function execute(string $toolClass, ToolContext $context, ToolIOInterface $io): ?ToolContext
    {
        $tool = $this->builder->build($toolClass, $context, $this->derivedResolvers);
        if ($tool === null) {
            throw new \RuntimeException(sprintf(
                'Tool %s cannot be built for the current context — call canBuild() before execute().',
                $toolClass,
            ));
        }
        return $tool->execute($io);
    }

    /**
     * Calls `onContextChange()` on all tools implementing {@see WithContextChangeInterface}.
     *
     * Two passes guarantee setup (e.g. dynamic CR registration) before derived-service consumers:
     *   - Pass 1: tools whose constructor needs no derived types (only registered context types + framework services)
     *   - Pass 2: tools whose constructor needs at least one derived type (e.g. ContentRepositoryMaintainer)
     *
     * Tools whose required constructor params cannot be resolved from $new are silently skipped.
     */
    public function notifyContextChange(ToolContext $old, ToolContext $new, ToolIOInterface $io): void
    {
        $pass1 = [];
        $pass2 = [];
        foreach ($this->toolClasses as $toolClass) {
            if (!is_a($toolClass, WithContextChangeInterface::class, true)) {
                continue;
            }
            if (!method_exists($toolClass, 'onContextChange')) {
                continue;
            }
            if ($this->builder->constructorHasDerivedParam($toolClass, $this->derivedResolvers)) {
                $pass2[] = $toolClass;
            } else {
                $pass1[] = $toolClass;
            }
        }

        foreach ([$pass1, $pass2] as $pass) {
            foreach ($pass as $toolClass) {
                $tool = $this->builder->build($toolClass, $new, $this->derivedResolvers);
                if ($tool === null) {
                    continue; // required dep missing — skip silently
                }
                $tool->onContextChange($old, $new, $io);
            }
        }
    }

    /**
     * Read the {@see ToolMeta} attribute from the tool class, falling back to derived values:
     * - shortName: class basename without "Tool" suffix, CamelCase → kebab-case
     * - group: last namespace segment before "Tool\" sub-namespace (e.g. Tool\Node → "Node")
     *
     * @param class-string<ToolInterface> $toolClass
     */
    private function resolveToolMeta(string $toolClass): ToolMeta
    {
        $ref = new \ReflectionClass($toolClass);
        $attrs = $ref->getAttributes(ToolMeta::class);
        if ($attrs !== []) {
            return $attrs[0]->newInstance();
        }

        $baseName = $ref->getShortName();
        $withoutSuffix = preg_replace('/Tool$/', '', $baseName) ?? $baseName;
        $shortName = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $withoutSuffix));

        $ns = $ref->getNamespaceName();
        if (preg_match('/\\\\Tool\\\\([^\\\\]+)/', $ns, $m)) {
            $group = $m[1];
        } else {
            $group = 'Other';
        }

        return new ToolMeta(shortName: $shortName, group: $group);
    }
}
