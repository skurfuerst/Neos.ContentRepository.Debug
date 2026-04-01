<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\ContentRepository;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Copies all tables of a CR (events, subscriptions, projections) to a target CR via exact
 *           DB-level clone — {@see CrCopyTool::execute()} for full flow description.
 *
 * Use this to create a safe shadow CR before running {@see EventGraveyardTool}.
 */
#[ToolMeta(shortName: 'crCopy', group: 'ContentRepository')]
#[Flow\Scope('singleton')]
final class CrCopyTool implements ToolInterface
{
    #[Flow\Inject]
    protected Connection $dbal;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Copy CR tables to another CR (exact DB-level clone)';
    }

    /**
     * Copies all tables with the source CR prefix to a new target CR prefix via:
     *   1. DROP existing target tables (with confirm if events table has rows)
     *   2. CREATE TABLE {dst} LIKE {src}  (exact structure clone)
     *   3. INSERT INTO {dst} SELECT * FROM {src}  (exact data clone)
     *
     * No Neos/Flow CR layer is involved — this operates purely at the DBAL level.
     */
    public function execute(
        ToolIOInterface $io,
        ContentRepositoryId $cr,
    ): ?ToolContext {
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

        // Discover all source tables (no exclusions — exact clone includes graveyard)
        $srcPrefix = 'cr_' . $cr->value . '_';
        /** @var list<string> $srcTables */
        $srcTables = $this->dbal->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name LIKE :prefix
             ORDER BY table_name",
            ['prefix' => $srcPrefix . '%']
        );

        if ($srcTables === []) {
            $io->writeError('No tables found for source CR "' . $cr->value . '".');
            return null;
        }

        // Check if target CR tables already exist
        $dstPrefix = 'cr_' . $targetCrId->value . '_';
        /** @var list<string> $existingDstTables */
        $existingDstTables = $this->dbal->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name LIKE :prefix
             ORDER BY table_name",
            ['prefix' => $dstPrefix . '%']
        );

        if ($existingDstTables !== []) {
            // Only prompt for confirmation if the events table is non-empty
            $dstEventsTable = $dstPrefix . 'events';
            $eventsCount = in_array($dstEventsTable, $existingDstTables, strict: true)
                ? (int)$this->dbal->fetchOne("SELECT COUNT(*) FROM {$dstEventsTable}")
                : 0;

            if ($eventsCount > 0) {
                $confirmed = $io->confirm(sprintf(
                    'Target CR "%s" already exists and has %d event(s). Drop all target tables and overwrite?',
                    $targetCrId->value,
                    $eventsCount,
                ));
                if (!$confirmed) {
                    $io->writeLine('Aborted.');
                    return null;
                }
            }

            foreach ($existingDstTables as $table) {
                $this->dbal->executeStatement("DROP TABLE {$table}");
            }
        }

        // Exact DB-level clone: CREATE TABLE … LIKE + INSERT INTO … SELECT *
        /** @var list<array{src: string, dst: string, rows: int}> $result */
        $result = [];

        $io->progress(
            sprintf('Copying %d table(s) from "%s" to "%s"', count($srcTables), $cr->value, $targetCrId->value),
            count($srcTables),
            function (callable $advance) use ($srcTables, $srcPrefix, $dstPrefix, &$result): void {
                foreach ($srcTables as $srcTable) {
                    $suffix = substr($srcTable, strlen($srcPrefix));
                    $dstTable = $dstPrefix . $suffix;
                    $rows = (int)$this->dbal->fetchOne("SELECT COUNT(*) FROM {$srcTable}");
                    $this->dbal->executeStatement("CREATE TABLE {$dstTable} LIKE {$srcTable}");
                    $this->dbal->executeStatement("INSERT INTO {$dstTable} SELECT * FROM {$srcTable}");
                    $result[] = ['src' => $srcTable, 'dst' => $dstTable, 'rows' => $rows];
                    $advance();
                }
            }
        );

        $io->writeTable(
            ['Source Table', 'Target Table', 'Rows'],
            array_map(fn(array $r) => [$r['src'], $r['dst'], (string)$r['rows']], $result),
        );

        $io->writeInfo(sprintf('Done. Copied %d table(s) to "%s".', count($result), $targetCrId->value));
        return null;
    }
}
