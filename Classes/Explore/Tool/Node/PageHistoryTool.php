<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Shows combined event history for a page and all its content nodes.
 *
 * Collects all descendant node aggregate IDs via the content subtree, then queries the event store
 * for events affecting any of them — giving a full picture of what changed on a page.
 */
#[ToolMeta(shortName: 'docHist', group: 'Events')]
final class PageHistoryTool implements ToolInterface
{
    #[Flow\Inject]
    protected Connection $connection;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Page: full event history';
    }

    public function execute(
        ToolIOInterface $io,
        ContentRepositoryId $cr,
        ContentSubgraphInterface $subgraph,
        NodeAggregateId $node,
    ): ?ToolContext {
        // Collect all node IDs on this page (the page itself + all content descendants)
        // Include the page node itself but exclude document descendants (subpages)
        $subtree = $subgraph->findSubtree($node, FindSubtreeFilter::create(nodeTypes: '!Neos.Neos:Document'));
        if ($subtree === null) {
            $io->writeError('Node not found in this subgraph.');
            return null;
        }

        $nodeIds = [];
        $this->collectNodeIds($subtree, $nodeIds);

        $io->writeNote(sprintf('Querying events for %d nodes on this page...', count($nodeIds)));

        $tableName = DoctrineEventStoreFactory::databaseTableName($cr);

        // Build query with IN clause for all node IDs using JSON_EXTRACT
        $qb = $this->connection->createQueryBuilder();
        $qb->select('sequencenumber', 'type', 'payload', 'recordedat')
            ->from($tableName)
            ->where('JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.nodeAggregateId\')) IN (:nodeIds)')
            ->setParameter('nodeIds', $nodeIds, Connection::PARAM_STR_ARRAY)
            ->orderBy('sequencenumber', 'ASC');

        try {
            $result = $qb->executeQuery();
        } catch (\Throwable $e) {
            $io->writeError('Failed to query event store: ' . $e->getMessage());
            return null;
        }

        $events = $result->fetchAllAssociative();

        if ($events === []) {
            $io->writeLine('No events found.');
            return null;
        }

        $io->writeNote(sprintf('%d events across %d nodes', count($events), count($nodeIds)));
        $io->writeLine('');

        $rows = [];
        foreach ($events as $event) {
            $type = $event['type'];
            $shortType = str_contains($type, ':') ? substr($type, strrpos($type, ':') + 1) : $type;

            $payload = json_decode($event['payload'], true);
            $eventNodeId = $payload['nodeAggregateId'] ?? '?';
            // Shorten UUID for readability
            $shortId = substr($eventNodeId, 0, 8);

            $rows[] = [
                $event['sequencenumber'],
                $shortType,
                $shortId,
                $event['recordedat'],
                $this->summarizePayload($payload, $shortType),
            ];
        }

        $io->writeTable(['Seq', 'Event', 'Node', 'Recorded at', 'Summary'], $rows);

        return null;
    }

    /** @param list<string> $nodeIds */
    private function collectNodeIds(Subtree $subtree, array &$nodeIds): void
    {
        $nodeIds[] = $subtree->node->aggregateId->value;
        foreach ($subtree->children as $child) {
            $this->collectNodeIds($child, $nodeIds);
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function summarizePayload(?array $payload, string $eventType): string
    {
        if ($payload === null) {
            return '';
        }

        return match (true) {
            str_contains($eventType, 'PropertiesWereSet') => $this->summarizeProperties($payload),
            str_contains($eventType, 'ReferenceWasSet'),
            str_contains($eventType, 'ReferencesWereSet') => $this->summarizeReferences($payload),
            default => $this->summarizeGeneric($payload),
        };
    }

    private function summarizeProperties(array $payload): string
    {
        $props = $payload['propertyValues'] ?? [];
        $names = array_keys($props);
        if ($names === []) {
            return '';
        }
        $list = implode(', ', array_slice($names, 0, 5));
        return count($names) > 5 ? $list . ' (+' . (count($names) - 5) . ')' : $list;
    }

    private function summarizeReferences(array $payload): string
    {
        $name = $payload['referenceName'] ?? '?';
        $refs = $payload['references'] ?? [];
        return sprintf('%s (%d refs)', $name, count($refs));
    }

    private function summarizeGeneric(array $payload): string
    {
        $parts = [];
        if (isset($payload['nodeTypeName'])) {
            $parts[] = $payload['nodeTypeName'];
        }
        if (isset($payload['tag'])) {
            $parts[] = 'tag:' . $payload['tag'];
        }
        return implode(' ', $parts);
    }
}
