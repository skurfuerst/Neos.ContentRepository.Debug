<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\ContentRepository;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Utility\ObjectAccess;

/**
 * @internal Copies all tables of a CR (events, subscriptions, projections) to a target CR.
 *
 * Use this to create a safe shadow CR before running {@see EventGraveyardTool}.
 * The target CR is set up via setUp() first, then all source tables are TRUNCATE + INSERT-SELECTed.
 * Graveyard tables are excluded so accumulated graveyard data is preserved across runs.
 *
 * Only available in Development context.
 */
#[ToolMeta(shortName: 'crCopy', group: 'ContentRepository')]
#[Flow\Scope('singleton')]
final class CrCopyTool implements ToolInterface
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $crRegistry;

    #[Flow\Inject]
    protected Connection $dbal;

    #[Flow\Inject]
    protected Bootstrap $bootstrap;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Copy CR tables to another CR (⚠ DEV only)';
    }

    public function execute(
        ToolIOInterface $io,
        ContentRepositoryId $cr,
    ): ?ToolContext {
        if (!$this->bootstrap->getContext()->isDevelopment()) {
            throw new \LogicException('CrCopyTool may only run in Development context.', 1748100002);
        }

        $targetId = trim($io->ask('Target CR ID (≤ 16 chars, e.g. "default_shadow"):'));
        if ($targetId === '') {
            $io->writeLine('Aborted.');
            return null;
        }

        try {
            $targetCrId = ContentRepositoryId::fromString($targetId);
        } catch (\InvalidArgumentException $e) {
            $io->writeError('Invalid CR ID: ' . $e->getMessage());
            return null;
        }

        if ($targetCrId->value === $cr->value) {
            $io->writeError('Source and target CR are identical — nothing to do.');
            return null;
        }

        // Discover which source tables exist (excluding graveyard tables)
        $srcPrefix = 'cr_' . $cr->value . '_';
        $tables = $this->dbal->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name LIKE :prefix
             AND table_name NOT LIKE '%_graveyard'
             ORDER BY table_name",
            ['prefix' => $srcPrefix . '%']
        );

        if ($tables === []) {
            $io->writeError('No tables found for source CR "' . $cr->value . '".');
            return null;
        }

        $io->writeError(sprintf(
            'Will set up target CR "%s" and copy %d table(s) from "%s". Target tables will be TRUNCATED.',
            $targetCrId->value,
            count($tables),
            $cr->value
        ));
        $confirm = trim($io->ask('Type "yes" to confirm'));
        if ($confirm !== 'yes') {
            $io->writeLine('Aborted.');
            return null;
        }

        // Set up the target CR (creates tables with fresh BOOTING subscriptions)
        $io->writeLine('Setting up target CR "' . $targetCrId->value . '"…');
        $this->setupTargetCr($cr, $targetCrId);

        // Copy all tables
        $dstPrefix = 'cr_' . $targetCrId->value . '_';
        $copied = 0;

        $io->progress(sprintf('Copying %d table(s)', count($tables)), count($tables), function (callable $advance) use ($tables, $srcPrefix, $dstPrefix, &$copied): void {
            foreach ($tables as $srcTable) {
                $suffix = substr($srcTable, strlen($srcPrefix));
                $dstTable = $dstPrefix . $suffix;
                if ($this->tableExists($dstTable)) {
                    $this->dbal->executeStatement("TRUNCATE TABLE {$dstTable}");
                    $this->dbal->executeStatement("INSERT INTO {$dstTable} SELECT * FROM {$srcTable}");
                    $copied++;
                }
                $advance();
            }
        });

        $io->writeInfo(sprintf('Done. Copied %d table(s) to "%s".', $copied, $targetCrId->value));
        return null;
    }

    private function setupTargetCr(ContentRepositoryId $sourceCrId, ContentRepositoryId $targetCrId): void
    {
        $settings = ObjectAccess::getProperty($this->crRegistry, 'settings', forceDirectAccess: true);
        $settings['contentRepositories'][$targetCrId->value] = $settings['contentRepositories'][$sourceCrId->value];
        $this->crRegistry->injectSettings($settings);

        $maintainer = $this->crRegistry->buildService($targetCrId, new ContentRepositoryMaintainerFactory());
        $error = $maintainer->setUp();
        if ($error !== null) {
            throw new \RuntimeException('Target CR setUp failed: ' . $error->getMessage(), 1748100003);
        }
    }

    private function tableExists(string $tableName): bool
    {
        return (int)$this->dbal->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t",
            ['t' => $tableName]
        ) > 0;
    }
}
