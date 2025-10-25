<?php

namespace Neos\ContentRepository\Debug\InternalServices;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;

/**
 * @extends ContentRepositoryServiceFactoryInterface<EventStoreDebuggingInternals>
 */
final readonly class EventStoreDebuggingInternalsFactory implements ContentRepositoryServiceFactoryInterface
{
    public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): ContentRepositoryServiceInterface
    {
        return new EventStoreDebuggingInternals($serviceFactoryDependencies->eventStore);
    }
}
