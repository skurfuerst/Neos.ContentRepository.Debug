<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\EventStore;

use Neos\ContentRepository\Debug\Explore\EventStore\EventPayloadSummarizer;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

/**
 * @internal Browse the raw event stream around a given sequence number to see what happened before/after.
 *           Lets the user pick individual events from the window to inspect their full payload.
 *
 * @see NodeHistoryTool for node-specific event history
 */
#[ToolMeta(shortName: 'seq', group: 'Events')]
final class EventContextTool implements ToolInterface
{
    private const int WINDOW = 10;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Event store: context around sequence number';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $input = $io->ask('Sequence number');
        $target = (int) $input;
        if ($target < 1) {
            $io->writeError('Invalid sequence number.');
            return null;
        }

        $min = max(1, $target - self::WINDOW);

        $stream = $this->eventStore->load(VirtualStreamName::all())
            ->withMinimumSequenceNumber(SequenceNumber::fromInteger($min))
            ->withMaximumSequenceNumber(SequenceNumber::fromInteger($target + self::WINDOW));

        $summarizer = new EventPayloadSummarizer();

        /** @var array<string, EventEnvelope> $envelopes key = seq as string */
        $envelopes = [];
        /** @var array<string, string> $choices key = seq, value = display label */
        $choices = [];

        foreach ($stream as $envelope) {
            $seq = $envelope->sequenceNumber->value;
            $seqKey = (string) $seq;
            $shortType = $this->shortenEventType($envelope->event->type->value);
            $summary = $summarizer->summarize($envelope->event->data->value, $shortType);
            $summaryPart = $summary !== '' ? '  ' . $summary : '';
            $prefix = $seq === $target ? '> ' : '  ';
            $choices[$seqKey] = sprintf(
                '%s[%s]%s  %s',
                $prefix,
                $shortType,
                $summaryPart,
                $envelope->recordedAt->format('Y-m-d H:i:s'),
            );
            $envelopes[$seqKey] = $envelope;
        }

        if ($envelopes === []) {
            $io->writeLine('No events found around sequence number ' . $target . '.');
            return null;
        }

        $selected = $io->chooseMultiple(
            sprintf('Events around seq %d (±%d) — select to inspect (blank = none)', $target, self::WINDOW),
            $choices,
            default: [(string) $target],
        );

        foreach ($selected as $seqKey) {
            $envelope = $envelopes[$seqKey];
            $shortType = $this->shortenEventType($envelope->event->type->value);
            $io->writeLine(sprintf('## Event %s: %s', $seqKey, $shortType));

            $payload = json_decode($envelope->event->data->value, true);
            if (!is_array($payload)) {
                $io->writeLine('(payload could not be decoded)');
                continue;
            }
            $pairs = [];
            foreach ($payload as $key => $value) {
                $pairs[(string)$key] = is_array($value) || is_object($value)
                    ? (string)json_encode($value)
                    : (string)$value;
            }
            $io->writeKeyValue($pairs);
        }

        return null;
    }

    private function shortenEventType(string $type): string
    {
        return str_contains($type, ':') ? substr($type, strrpos($type, ':') + 1) : $type;
    }
}
