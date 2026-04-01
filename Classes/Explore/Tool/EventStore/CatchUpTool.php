<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\EventStore;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\InternalServices\EventStoreDebuggingInternalsFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Catches up all subscriptions on the selected CR from any state.
 *
 * Runs boot() for BOOTING subscriptions, catchUpActive() for ACTIVE ones, and
 * reactivate() for ERROR/DETACHED ones — in sequence with a single progress bar.
 * Uses batched transactions (1 000 events/batch).
 * If errors remain after all passes, re-run the tool or use {@see EventGraveyardTool}
 * to isolate the bad events first.
 */
#[ToolMeta(shortName: 'catchUp', group: 'ContentRepository')]
#[Flow\Scope('singleton')]
final class CatchUpTool implements ToolInterface
{
    private const BATCH_SIZE = 1000;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $crRegistry;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Catch-up: boot + catchUp + reactivate all subscriptions';
    }

    public function execute(
        ToolIOInterface $io,
        ContentRepositoryId $cr,
    ): ?ToolContext {
        $internals = $this->crRegistry->buildService($cr, new EventStoreDebuggingInternalsFactory());

        $maxSeq = $internals->getMaxSequenceNumber()->value;

        // Read current subscription positions to size the progress bar accurately.
        // The engine starts from the lowest position across all non-idle subscriptions.
        $minPos = $maxSeq;
        foreach ($internals->subscriptionEngine->subscriptionStatus() as $sub) {
            if (in_array($sub->subscriptionStatus, [
                SubscriptionStatus::BOOTING,
                SubscriptionStatus::ACTIVE,
                SubscriptionStatus::ERROR,
                SubscriptionStatus::DETACHED,
            ], true)) {
                $minPos = min($minPos, $sub->subscriptionPosition->value);
            }
        }
        $eventsToProcess = max(1, $maxSeq - $minPos);

        $bootResult = null;
        $catchUpResult = null;
        $reactivateResult = null;

        $io->progress(
            sprintf('Catching up "%s" (~%d events)', $cr->value, $eventsToProcess),
            $eventsToProcess,
            function (callable $advance) use ($internals, &$bootResult, &$catchUpResult, &$reactivateResult): void {
                $bootResult = $internals->subscriptionEngine->boot(
                    progressCallback: static function () use ($advance): void { $advance(); },
                    batchSize: self::BATCH_SIZE,
                );
                $catchUpResult = $internals->subscriptionEngine->catchUpActive(
                    progressCallback: static function () use ($advance): void { $advance(); },
                    batchSize: self::BATCH_SIZE,
                );
                $reactivateResult = $internals->subscriptionEngine->reactivate(
                    progressCallback: static function () use ($advance): void { $advance(); },
                    batchSize: self::BATCH_SIZE,
                );
            }
        );

        $errors = array_merge(
            iterator_to_array($bootResult?->errors ?? []),
            iterator_to_array($catchUpResult?->errors ?? []),
            iterator_to_array($reactivateResult?->errors ?? []),
        );

        if ($errors !== []) {
            $io->writeError('Catch-up finished with errors. Re-run the tool or use "graveyardCatchUp" to isolate bad events.');
            foreach ($errors as $error) {
                $io->writeKeyValue([
                    'Subscription' => $error->subscriptionId->value,
                    'Message' => $error->message,
                ]);
            }
        } else {
            $io->writeInfo('All subscriptions active on "' . $cr->value . '".');
        }

        return null;
    }
}
