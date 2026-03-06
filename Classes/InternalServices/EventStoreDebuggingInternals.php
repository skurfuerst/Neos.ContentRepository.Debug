<?php

namespace Neos\ContentRepository\Debug\InternalServices;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

final readonly class EventStoreDebuggingInternals implements ContentRepositoryServiceInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private SubscriptionEngine $subscriptionEngine,
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

    public function resetAndBoot(\Closure $progressCallback): void
    {
        $this->subscriptionEngine->reset();
        $this->subscriptionEngine->boot(progressCallback: $progressCallback, batchSize: 1);
    }
}
