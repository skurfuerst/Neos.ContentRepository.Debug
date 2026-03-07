<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;
use Neos\Flow\Annotations as Flow;

/**
 * @api Call {@see ToolContextRegistry::register} in your package's Package.php to introduce new context dimensions.
 *
 * All lookup methods are {@internal} and used only by {@see ToolDispatcher} and {@see ToolContextSerializer}.
 */

#[Flow\Scope("singleton")]
final class ToolContextRegistry
{
    /** @var array<string, ToolContextTypeDescriptor> keyed by name */
    private array $byName = [];

    /** @var array<string, ToolContextTypeDescriptor> keyed by FQCN */
    private array $byType = [];

    /**
     * Register a new context dimension.
     *
     * @param string $name    Bag key used in {@see ToolContext::get} / {@see ToolContext::with} (e.g. 'node').
     * @param string $type    FQCN of the value object (e.g. NodeAggregateId::class).
     * @param string $alias   Short CLI flag alias (e.g. 'n' → --node / -n).
     * @param callable(string): object $fromString Deserialises the CLI string to the value object.
     * @param callable(object): string $toString   Serialises the value object to a CLI string.
     */
    public function register(
        string $name,
        string $type,
        string $alias,
        callable $fromString,
        callable $toString,
    ): void {
        $descriptor = new ToolContextTypeDescriptor($name, $type, $alias, $fromString, $toString);
        $this->byName[$name] = $descriptor;
        $this->byType[$type] = $descriptor;
    }

    /** @internal */
    public function getByName(string $name): ?ToolContextTypeDescriptor
    {
        return $this->byName[$name] ?? null;
    }

    /** @internal */
    public function getByType(string $fqcn): ?ToolContextTypeDescriptor
    {
        return $this->byType[$fqcn] ?? null;
    }

    /**
     * @internal
     * @return iterable<ToolContextTypeDescriptor>
     */
    public function all(): iterable
    {
        return $this->byName;
    }
}
