<?php

namespace Neos\ContentRepository\Debug;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\InternalServices\EventStoreDebuggingInternalsFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;
use Neos\Utility\ObjectAccess;

class ContentRepositoryDebugger
{

    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly Connection $connection,
    )
    {
    }

    public function execScriptFile(string $debugScriptFileName, ContentRepositoryId $contentRepositoryId): void
    {
        if (!file_exists($debugScriptFileName)) {
            throw new \InvalidArgumentException('ERROR: Debug Script File not found: ' . $debugScriptFileName);
        }

        $executor = static function(ContentRepositoryDebugger $dbg, ContentRepository $cr) use ($debugScriptFileName) {
            include $debugScriptFileName;
        };

        $executor(dbg: $this, cr: $this->contentRepositoryRegistry->get($contentRepositoryId));
    }

    public function setupCr(string $target, string $source = 'default', bool $prune = false): ContentRepository
    {
        $targetId = ContentRepositoryId::fromString($target);
        $settings = ObjectAccess::getProperty($this->contentRepositoryRegistry, 'settings', true);

        $settings['contentRepositories'][$targetId->value] = $settings['contentRepositories'][$source];

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

    public function copyEvents(ContentRepository $source, ContentRepository $target, EventFilter\EventFilter $filter, $force = false): void
    {
        // Hash-based idempotency: We hash the filter configuration + highest sequence number from source.
        // This hash is stored as a table comment on the target events table.
        // If the hash matches, we skip the copy operation (already done).
        // If different, we re-run the copy to ensure target matches the filtered source state.
        $sourceTableName = DoctrineEventStoreFactory::databaseTableName($source->id);
        $targetTableName = DoctrineEventStoreFactory::databaseTableName($target->id);

        $sourceDbgInternals = $this->contentRepositoryRegistry->buildService($source->id, new EventStoreDebuggingInternalsFactory());
        $expectedTableDebugComment = $sourceDbgInternals->getMaxSequenceNumber()->value . '_' . $filter->asHash();
        $actualTableDebugComment = $this->getTableDebugComment($targetTableName);
        if ($force === false && $expectedTableDebugComment === $actualTableDebugComment) {
            // Nothing to be done, idempotent
            return;
        }

        $this->connection->executeStatement("TRUNCATE TABLE {$targetTableName}");
        $sql = "INSERT INTO {$targetTableName} 
            SELECT * FROM {$sourceTableName} WHERE " . $filter->asWhereClause();
        echo $filter->asWhereClause() . "\n";
        echo json_encode($filter->parameters) . "\n";
        $this->connection->executeStatement($sql, $filter->parameters);
        $this->setTableDebugComment($targetTableName, $expectedTableDebugComment);
    }


    private function getTableDebugComment(string $tableName): ?string
    {
        $comment = $this->connection->fetchOne(
            "SELECT table_comment FROM information_schema.tables 
         WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        );

        if (!$comment || !str_starts_with($comment, 'dbg:')) {
            return null;
        }

        return substr($comment, 4); // Remove 'dbg:' prefix
    }

    private function setTableDebugComment(string $tableName, string $comment): void
    {
        $comment = 'dbg:' . $comment;
        $this->connection->executeStatement(
            "ALTER TABLE {$tableName} COMMENT = ?",
            [$comment]
        );
    }
}
