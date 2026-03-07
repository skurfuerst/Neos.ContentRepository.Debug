<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

/**
 * @internal Carries the metadata for one registered context dimension — owned and looked up by {@see ToolContextRegistry}.
 */

readonly final class ToolContextTypeDescriptor
{
    /**
     * @param string $name  Registered name used as the context bag key (e.g. 'node').
     * @param string $type  Fully-qualified PHP class name of the value (e.g. NodeAggregateId::class).
     * @param string $alias Short CLI alias (e.g. 'n').
     * @param callable(string): object $fromString Deserialises a CLI string to the value object.
     * @param callable(object): string $toString   Serialises the value object to a CLI string.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $alias,
        private mixed $fromString,
        private mixed $toString,
    ) {}

    public function fromString(string $value): object
    {
        return ($this->fromString)($value);
    }

    public function toString(object $value): string
    {
        return ($this->toString)($value);
    }
}
