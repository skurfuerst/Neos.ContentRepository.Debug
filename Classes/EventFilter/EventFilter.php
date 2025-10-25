<?php

namespace Neos\ContentRepository\Debug\EventFilter;

final readonly class EventFilter
{
    private function __construct(private array $where, public array $parameters)
    {

    }

    public static function create(): self
    {
        return new self([], []);
    }

    public function skipEventTypes(string ...$names): self
    {
        $filter = $this;
        foreach ($names as $name) {
            if (class_exists($name)) {
                /** same logic as in {@see \Neos\ContentRepository\Core\EventStore\EventNormalizer::create()} */
                $name = substr($name, strrpos($name, '\\') + 1);
            }

            $filter = $filter->where('type != ?', [$name]);
        }

        return $filter;
    }

    public function where(string $sql, array $params): self
    {
        return new self(
            [...$this->where, $sql],
            [...$this->parameters, ...$params]
        );
    }

    public function asHash(): string
    {
        return sha1(json_encode([$this->where, $this->parameters]));
    }

    public function asWhereClause(): string
    {
        if (count($this->where) === 0) {
            return '1=1';
        };

        return '((' . implode(') AND (', $this->where) . '))';
    }
}
