<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

/**
 * @api Immutable bag of typed context values keyed by registered name — passed to every tool and updated between steps.
 *
 * Values are looked up by name ({@see ToolContext::get}) or by PHP class ({@see ToolContext::getByType},
 * used internally by {@see ToolDispatcher}).
 * New instances are created via {@see ToolContext::with} / {@see ToolContext::without} — never mutated.
 *
 * When created with a {@see ToolContextRegistry} via {@see ToolContext::create}, tools can use
 * {@see ToolContext::withFromString} to set values from user input without depending on the registry directly.
 */
final class ToolContext
{
    /**
     * @param array<string, object> $values
     */
    private function __construct(
        private readonly array $values,
        private readonly ?ToolContextRegistry $registry = null,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Create a context backed by a {@see ToolContextRegistry} — enables {@see withFromString}.
     */
    public static function create(ToolContextRegistry $registry): self
    {
        return new self([], $registry);
    }

    public function with(string $name, object $value): self
    {
        return new self(array_merge($this->values, [$name => $value]), $this->registry);
    }

    /**
     * Deserialise a string value via the registered fromString callback and store it under $name.
     * Requires this context to have been created with {@see ToolContext::create}.
     *
     * @throws \LogicException if no registry is available or $name is not registered.
     */
    public function withFromString(string $name, string $stringValue): self
    {
        if ($this->registry === null) {
            throw new \LogicException('withFromString() requires a ToolContextRegistry — use ToolContext::create() instead of ToolContext::empty().');
        }
        $descriptor = $this->registry->getByName($name);
        if ($descriptor === null) {
            throw new \LogicException(sprintf('Context type "%s" is not registered.', $name));
        }
        return $this->with($name, $descriptor->fromString($stringValue));
    }

    public function without(string $name): self
    {
        $values = $this->values;
        unset($values[$name]);
        return new self($values, $this->registry);
    }

    public function get(string $name): ?object
    {
        return $this->values[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /**
     * @internal Used by {@see ToolDispatcher} to resolve execute() parameters by PHP class name.
     */
    public function getByType(string $fqcn): ?object
    {
        foreach ($this->values as $value) {
            if ($value instanceof $fqcn) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @internal Used by {@see ToolDispatcher} to check tool availability.
     */
    public function hasByType(string $fqcn): bool
    {
        return $this->getByType($fqcn) !== null;
    }
}
