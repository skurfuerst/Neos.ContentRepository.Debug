<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\EventStore;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\InternalServices\EventStoreDebuggingInternalsFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Core\Bootstrap;

/**
 * @internal Truncates all projection tables for the selected CR and resets subscription positions to 0.
 *
 * Only available in Development context. Follow up with {@see CatchUpTool} or ./flow subscription:replayAll.
 */
#[ToolMeta(shortName: 'resetProjections', group: 'ContentRepository')]
final class ResetTool implements ToolInterface
{
    public function __construct(
        private readonly ContentRepositoryRegistry $crRegistry,
        private readonly Bootstrap $bootstrap,
        private readonly ContentRepositoryId $cr,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Reset CR: truncate all projections (⚠ DEV only)';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        if (!$this->bootstrap->getContext()->isDevelopment()) {
            throw new \LogicException('ResetTool may only run in Development context.', 1748100001);
        }

        if (!$io->confirm('TRUNCATE all projection tables and reset subscription positions for CR "' . $this->cr->value . '"?')) {
            $io->writeLine('Aborted.');
            return null;
        }

        $internals = $this->crRegistry->buildService($this->cr, new EventStoreDebuggingInternalsFactory());
        $internals->subscriptionEngine->reset();

        $io->writeInfo('Done. Run "catchUp" or ./flow subscription:replayAll to replay.');
        return null;
    }
}
