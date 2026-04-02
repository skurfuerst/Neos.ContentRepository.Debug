<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\ContentRepository;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\DetachedSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\Tool\WithContextChangeInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Displays subscription status overview, detailed error info for broken projections,
 *           and DB table sizes for the current CR.
 * @see ContentRepositoryMaintainer::status() for the underlying status API.
 */
#[ToolMeta(shortName: 'status', group: 'ContentRepository')]
final class StatusTool implements ToolInterface, WithContextChangeInterface
{
    public function __construct(
        private readonly Connection                  $dbal,
        private readonly ContentRepositoryId         $cr,
        private readonly ContentRepositoryMaintainer $maintainer,
    )
    {
    }

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Subscription status & errors';
    }

    /**
     * Quick subscription health-check on every CR change (and bootstrap).
     * Shows a warning note for DETACHED or non-ACTIVE subscriptions without the full status table.
     * Previously lived in CrCommandController::displaySubscriptionWarnings() — moved here so the
     * session loop itself is responsible, not the CLI entry point.
     *
     * ContentRepositoryMaintainer is a derived type → pass 2 in notifyContextChange,
     * which guarantees dynamic CR registration (pass 1) has already run. ✓
     */
    public function onContextChange(ToolContext $old, ToolContext $new, ToolIOInterface $io): void
    {
        $prevCrId = $old->getByType(ContentRepositoryId::class);
        $nextCrId = $new->getByType(ContentRepositoryId::class);
        if ($nextCrId !== null && $prevCrId !== null && $nextCrId->equals($prevCrId)) {
            // nothing changed regarding to CR, so do not show
            return;
        } elseif ($nextCrId === null && $prevCrId === null) {
            // no CR selected, and did not change
            return;
        }
        try {
            $crStatus = $this->maintainer->status();
        } catch (\Throwable) {
            return; // pre-setup or broken DB — don't block the session
        }

        $hasProblems = false;
        foreach ($crStatus->subscriptionStatus as $status) {
            if ($status instanceof DetachedSubscriptionStatus) {
                $io->writeNote(sprintf(
                    'Subscription "%s" is DETACHED at position %d',
                    $status->subscriptionId->value,
                    $status->subscriptionPosition->value,
                ));
                $hasProblems = true;
                continue;
            }
            if ($status instanceof ProjectionSubscriptionStatus && $status->subscriptionStatus !== SubscriptionStatus::ACTIVE) {
                if ($status->subscriptionStatus === SubscriptionStatus::ERROR) {
                    $errorMsg = $status->subscriptionError?->errorMessage ?? '(no details)';
                    $io->writeError(sprintf(
                        'Subscription "%s" is in ERROR at position %d: %s',
                        $status->subscriptionId->value,
                        $status->subscriptionPosition->value,
                        $errorMsg,
                    ));
                } else {
                    $io->writeNote(sprintf(
                        'Subscription "%s" is %s at position %d',
                        $status->subscriptionId->value,
                        $status->subscriptionStatus->value,
                        $status->subscriptionPosition->value,
                    ));
                }
                $hasProblems = true;
            }
        }

        if ($hasProblems) {
            $io->writeNote('Use "status" for full stack traces.');
        }
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        try {
            $crStatus = $this->maintainer->status();
        } catch (\Throwable $e) {
            $io->writeError('Could not retrieve status: ' . $e->getMessage());
            return null;
        }

        $positionInfo = $crStatus->eventStorePosition !== null
            ? 'Event store sequence_number: ' . $crStatus->eventStorePosition->value
            : 'Event store sequence_number: unknown';
        $io->writeLine($positionInfo);

        $rows = [];
        $errorDetails = [];

        foreach ($crStatus->subscriptionStatus as $status) {
            if ($status instanceof DetachedSubscriptionStatus) {
                $rows[] = [
                    $status->subscriptionId->value,
                    'DETACHED',
                    (string)$status->subscriptionPosition->value,
                ];
                continue;
            }

            if ($status instanceof ProjectionSubscriptionStatus) {
                $statusLabel = $status->subscriptionStatus->value;
                $rows[] = [
                    $status->subscriptionId->value,
                    $statusLabel,
                    (string)$status->subscriptionPosition->value,
                ];

                if ($status->subscriptionStatus === SubscriptionStatus::ERROR && $status->subscriptionError !== null) {
                    $errorDetails[] = $status;
                }
            }
        }

        if ($rows === []) {
            $io->writeNote('No subscriptions registered. Run ./flow cr:setup first.');
            return null;
        }

        $io->writeTable(['Subscription', 'Status', 'Position'], $rows);

        $this->writeTableSizes($io);

        foreach ($errorDetails as $status) {
            $io->writeLine('');
            $io->writeError('Error in ' . $status->subscriptionId->value);
            $io->writeKeyValue([
                'Previous status' => $status->subscriptionError->previousStatus->value,
                'Message' => $status->subscriptionError->errorMessage,
            ]);
            if ($status->subscriptionError->errorTrace !== null) {
                $io->writeLine('');
                $io->writeLine('Stack trace:');
                $io->writeLine($status->subscriptionError->errorTrace);
            }
        }

        return null;
    }

    private function writeTableSizes(ToolIOInterface $io): void
    {
        $prefix = 'cr_' . $this->cr->value . '_';
        /** @var list<string> $tables */
        $tables = $this->dbal->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name LIKE :prefix
             ORDER BY table_name',
            ['prefix' => $prefix . '%']
        );

        if ($tables === []) {
            return;
        }

        $rows = array_map(
            fn(string $table) => [$table, (string)(int)$this->dbal->fetchOne("SELECT COUNT(*) FROM {$table}")],
            $tables,
        );

        $io->writeLine('');
        $io->writeTable(['Table', 'Rows'], $rows);
    }
}
