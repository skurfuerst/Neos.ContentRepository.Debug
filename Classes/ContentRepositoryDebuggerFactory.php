<?php

namespace Neos\ContentRepository\Debug;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;

class ContentRepositoryDebuggerFactory implements ContentRepositoryServiceFactoryInterface
{

    public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): ContentRepositoryServiceInterface
    {
        return new ContentRepositoryDebugger($serviceFactoryDependencies->contentRepository);
    }
}
