<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\EventStore\EventPayloadSummarizer;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;

/**
 * @internal Shows the event history for a node aggregate by querying the event store directly.
 *
 * Searches the event payload JSON for the nodeAggregateId to find all events that affected this node.
 */
#[ToolMeta(shortName: 'nHist', group: 'Events')]
final class NodeHistoryTool implements ToolInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContentRepositoryId $cr,
        private readonly NodeAggregateId $node,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: event history';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $tableName = DoctrineEventStoreFactory::databaseTableName($this->cr);

        $qb = $this->connection->createQueryBuilder();
        $qb->select('sequencenumber', 'type', 'payload', 'recordedat')
            ->from($tableName)
            ->where('JSON_EXTRACT(payload, :jsonPath) = :nodeId')
            ->setParameter('jsonPath', '$.nodeAggregateId')
            ->setParameter('nodeId', $this->node->value)
            ->orderBy('sequencenumber', 'ASC');

        try {
            $result = $qb->executeQuery();
        } catch (\Throwable $e) {
            $io->writeError('Failed to query event store: ' . $e->getMessage());
            return null;
        }

        $events = $result->fetchAllAssociative();

        if ($events === []) {
            $io->writeLine('No events found for this node aggregate.');
            return null;
        }

        $io->writeNote(sprintf('%d events for node %s', count($events), $this->node->value));
        $io->writeLine('');

        $summarizer = new EventPayloadSummarizer();
        $rows = [];
        foreach ($events as $event) {
            $type = $event['type'];
            // Shorten event type: "NodePropertiesWereSet" from "Neos.ContentRepository:NodePropertiesWereSet"
            $shortType = str_contains($type, ':') ? substr($type, strrpos($type, ':') + 1) : $type;

            $rows[] = [
                $event['sequencenumber'],
                $shortType,
                $event['recordedat'],
                $summarizer->summarize($event['payload'], $shortType),
            ];
        }

        $io->writeTable(['Seq', 'Event', 'Recorded at', 'Summary'], $rows);

        return null;
    }
}
