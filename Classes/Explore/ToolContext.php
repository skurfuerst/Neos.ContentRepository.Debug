<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\Flow\Annotations as Flow;

/**
 * @api Immutable bag of typed context values keyed by registered name — passed to every tool and updated between steps.
 *
 * Values are looked up by name ({@see ToolContext::get}) or by PHP class ({@see ToolContext::getByType},
 * used internally by {@see ToolDispatcher}).
 * New instances are created via {@see ToolContext::with} / {@see ToolContext::without} — never mutated.
 * @Flow\Proxy(false)
 */

final class ToolContext
{
    /** @param array<string, object> $values */
    private function __construct(private readonly array $values) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function with(string $name, object $value): self
    {
        return new self(array_merge($this->values, [$name => $value]));
    }

    public function without(string $name): self
    {
        $values = $this->values;
        unset($values[$name]);
        return new self($values);
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
