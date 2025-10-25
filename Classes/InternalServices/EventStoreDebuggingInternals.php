<?php

namespace Neos\ContentRepository\Debug\InternalServices;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

final readonly class EventStoreDebuggingInternals implements ContentRepositoryServiceInterface
{
    public function __construct(
        private readonly EventStoreInterface $eventStore
    )
    {
    }

    public function getMaxSequenceNumber(): SequenceNumber
    {
        foreach ($this->eventStore->load(VirtualStreamName::all())->backwards()->limit(1) as $eventEnvelope) {
            return $eventEnvelope->sequenceNumber;
        }
        return SequenceNumber::none();
    }
}
