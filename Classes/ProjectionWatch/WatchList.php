<?php

namespace Neos\ContentRepository\Debug\ProjectionWatch;

use Neos\EventStore\Model\EventEnvelope;

final class WatchList
{
    /** @var array<string, \Closure> */
    private array $expressions = [];

    /** @var array<string, string> name => normalized string */
    private array $previousValues = [];

    /** @var list<array{seq: int, event: string, watch: string, from: string, to: string}> */
    private array $changes = [];

    public function add(string $name, \Closure $fn): void
    {
        $this->expressions[$name] = $fn;
    }

    /**
     * @internal called by ContentRepositoryDebugger during replay
     */
    public function evaluate(EventEnvelope $envelope): void
    {
        foreach ($this->expressions as $name => $fn) {
            try {
                $current = $this->normalize($fn());
            } catch (\Throwable $e) {
                $current = '(error ' . get_class($e) . ': ' . $e->getMessage() . ')';
            }
            $previous = $this->previousValues[$name] ?? null;

            if ($previous !== $current) {
                $this->changes[] = [
                    'seq' => $envelope->sequenceNumber->value,
                    'event' => $envelope->event->type->value,
                    'watch' => $name,
                    'from' => $previous ?? '(initial)',
                    'to' => $current,
                ];
                $this->previousValues[$name] = $current;
            }
        }
    }

    /**
     * @return list<array{seq: int, event: string, watch: string, from: string, to: string}>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function reset(): void
    {
        $this->previousValues = [];
        $this->changes = [];
    }

    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '(null)';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            return json_encode($value->jsonSerialize(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }
        if (is_object($value) && property_exists($value, 'value')) {
            return (string)$value->value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return serialize($value);
    }
}
