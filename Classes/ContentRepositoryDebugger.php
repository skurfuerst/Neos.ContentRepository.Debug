<?php

namespace Neos\ContentRepository\Debug;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\Service\ContentStreamPrunerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\DebugView\DebugViewCreator;
use Neos\ContentRepository\Debug\InternalServices\EventStoreDebuggingInternalsFactory;
use Neos\ContentRepository\Debug\InternalServices\LowLevelDatabaseUtil;
use Neos\ContentRepository\Debug\ProjectionWatch\WatchList;
use Neos\ContentRepository\Debug\Query\DebugQueryBuilder;
use Neos\ContentRepository\Debug\Query\EventLogQueryBuilder;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;
use Neos\Utility\ObjectAccess;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

class ContentRepositoryDebugger
{

    private readonly LowLevelDatabaseUtil $lowLevelDatabaseUtil;
    public readonly ContentRepository $contentRepository;
    public readonly WatchList $watches;

    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        public readonly Connection                 $db,
    )
    {
        $this->lowLevelDatabaseUtil = new LowLevelDatabaseUtil($this->db);
        $this->watches = new WatchList();
    }

    public function execScriptFile(
        string $debugScriptFileName,
        ContentRepositoryId $contentRepositoryId,
        ?\Neos\ContentRepository\Debug\Explore\Script\ScriptToolRunner $tools = null,
    ): void {
        if (!file_exists($debugScriptFileName)) {
            throw new \InvalidArgumentException('ERROR: Debug Script File not found: ' . $debugScriptFileName);
        }

        $executor = static function (
            ContentRepositoryDebugger $dbg,
            ContentRepository $cr,
            ?ScriptToolRunner $tools,
        ) use ($debugScriptFileName) {
            include $debugScriptFileName;
        };

        $this->contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $executor(dbg: $this, cr: $this->contentRepository, tools: $tools);
    }

    public function setupCr(string $target, bool $prune = false): ContentRepository
    {
        $targetId = ContentRepositoryId::fromString($target);
        $settings = ObjectAccess::getProperty($this->contentRepositoryRegistry, 'settings', true);

        $settings['contentRepositories'][$targetId->value] = $settings['contentRepositories'][$this->contentRepository->id->value];

        $this->contentRepositoryRegistry->injectSettings($settings);
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService($targetId, new ContentRepositoryMaintainerFactory());

        $result = $contentRepositoryMaintainer->setUp();
        if ($result !== null) {
            throw new \RuntimeException('ERROR: ' . $result->getMessage());
        }
        if ($prune) {
            $result = $contentRepositoryMaintainer->prune();
            if ($result !== null) {
                throw new \RuntimeException('ERROR: ' . $result->getMessage());
            }
        }

        return $this->contentRepositoryRegistry->get($targetId);
    }

    public function copyEvents(ContentRepository $target, ?EventFilter\EventFilter $filter = null, $force = false): void
    {
        if ($filter === null) {
            $filter = EventFilter\EventFilter::create();
        }
        // Hash-based idempotency: We hash the filter configuration + highest sequence number from source.
        // This hash is stored as a table comment on the target events table.
        // If the hash matches, we skip the copy operation (already done).
        // If different, we re-run the copy to ensure target matches the filtered source state.
        $sourceTableName = DoctrineEventStoreFactory::databaseTableName($this->contentRepository->id);
        $targetTableName = DoctrineEventStoreFactory::databaseTableName($target->id);

        // Figure out whether we need to do anything
        $sourceDbgInternals = $this->contentRepositoryRegistry->buildService($this->contentRepository->id, new EventStoreDebuggingInternalsFactory());
        $expectedTableDebugComment = $sourceDbgInternals->getMaxSequenceNumber()->value . '_' . $filter->asHash();
        $actualTableDebugComment = $this->lowLevelDatabaseUtil->getTableDebugComment($targetTableName);
        if ($force === false && $expectedTableDebugComment === $actualTableDebugComment) {
            // Nothing to be done, idempotent
            return;
        }

        // Truncate and re-insert
        $this->db->executeStatement("TRUNCATE TABLE {$targetTableName}");
        $sql = "INSERT INTO {$targetTableName}
            SELECT * FROM {$sourceTableName} WHERE " . $filter->asWhereClause();
        $this->db->executeStatement($sql, $filter->parameters);

        $this->lowLevelDatabaseUtil->setTableDebugComment($targetTableName, $expectedTableDebugComment);
    }

    public function queryEvents(?ContentRepository $cr = null): EventLogQueryBuilder
    {
        $contentRepositoryToQuery = $cr ?? $this->contentRepository;
        return new EventLogQueryBuilder($this->db, DoctrineEventStoreFactory::databaseTableName($contentRepositoryToQuery->id));
    }

    public function use(ContentRepository $newContentRepository): void
    {
        $this->contentRepository = $newContentRepository;
    }

    public function printTable(\Doctrine\DBAL\Result $queryResult, string $pivotBy = null): void
    {
        $rows = $queryResult->fetchAllAssociative();
        $rows = DebugArrayUtils::pivot($rows, $pivotBy);

        $output = new BufferedOutput();
        $table = new Table($output);

        if (empty($rows)) {
            echo "No results found.\n";
            return;
        }

        $headers = array_keys($rows[0]);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
        echo $output->fetch();
    }

    public function printRecords(\Doctrine\DBAL\Result $queryResult, string $pivotBy = null): void
    {
        $rows = $queryResult->fetchAllAssociative();
        $rows = DebugArrayUtils::pivot($rows, $pivotBy);

        if (empty($rows)) {
            echo "No results found.\n";
            return;
        }

        $output = new BufferedOutput();

        foreach ($rows as $index => $row) {
            // Display record number/separator
            $output->writeln(sprintf('<info>Record %d:</info>', $index + 1));

            $table = new Table($output);
            $table->setHeaders(['Field', 'Value']);

            $tableRows = [];
            foreach ($row as $key => $value) {
                // Pretty-print JSON values
                if (is_string($value) && self::isJson($value)) {
                    $decoded = json_decode($value, true);
                    $value = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                $tableRows[] = [$key, $value];
            }

            $table->setRows($tableRows);
            $table->render();

            // Add spacing between records
            if ($index < count($rows) - 1) {
                $output->writeln('');
            }
        }

        echo $output->fetch();
    }

    private static function isJson(string $string): bool
    {
        if (empty($string) || !in_array($string[0], ['{', '['])) {
            return false;
        }

        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }


    public function contentStreamStatus(): void
    {
        $contentStreamPruner = $this->contentRepositoryRegistry->buildService($this->contentRepository->id, new ContentStreamPrunerFactory());
        $contentStreamPruner->outputStatus(function ($output = '') {
            echo $output . "\n";
        });
    }

    public function contentStreamRemoveDangling(): void
    {
        $contentStreamPruner = $this->contentRepositoryRegistry->buildService($this->contentRepository->id, new ContentStreamPrunerFactory());
        $contentStreamPruner->outputStatus(function ($output = '') {
            echo $output . "\n";
        });
        $contentStreamPruner->removeDanglingContentStreams(
            function ($output) {
                echo $output . "\n";
            },
            new \DateTimeImmutable('now')
        );
    }

    public function setupDebugViews(ContentRepositoryId $contentRepositoryId): void
    {
        $debugViewCreator = new DebugViewCreator($this->db, $contentRepositoryId);
        $debugViewCreator->createDebugViews();
    }

    public function replayProjections(): void
    {
        $internals = $this->contentRepositoryRegistry->buildService($this->contentRepository->id, new EventStoreDebuggingInternalsFactory());

        $printedCount = 0;
        $printNewChanges = function () use (&$printedCount) {
            $all = $this->watches->getChanges();
            for ($i = $printedCount; $i < count($all); $i++) {
                $c = $all[$i];
                echo sprintf("  seq=%-6d  %-50s  %s: %s → %s\n", $c['seq'], $c['event'], $c['watch'], $c['from'], $c['to']);
            }
            $printedCount = count($all);
        };

        $prevEnvelope = null;
        $internals->resetAndBoot(function (\Neos\EventStore\Model\EventEnvelope $e) use (&$prevEnvelope, $printNewChanges) {
            if ($prevEnvelope !== null) {
                $this->watches->evaluate($prevEnvelope);
                $printNewChanges();
            }
            $prevEnvelope = $e;
        });

        if ($prevEnvelope !== null) {
            $this->watches->evaluate($prevEnvelope);
            $printNewChanges();
        }

        $this->watches->reset();
    }

    public function printArray(array $rows): void
    {
        if (empty($rows)) {
            echo "No changes recorded.\n";
            return;
        }

        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders(array_keys($rows[0]));
        $table->setRows($rows);
        $table->render();
        echo $output->fetch();
    }

}
