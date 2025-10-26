<?php

namespace Neos\ContentRepository\Debug\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Neos\ContentRepository\Debug\EventFilter\EventFilter;

class EventLogQueryBuilder
{


    private readonly \Doctrine\DBAL\Query\QueryBuilder $queryBuilder;

    public function __construct(Connection $db, string $databaseTableName)
    {
        $this->queryBuilder = $db->createQueryBuilder();
        $this->queryBuilder
            ->from($databaseTableName);
    }

    public function whereRecordedAtBetween(string $from, string $to): self
    {
        $this->queryBuilder->andWhere('recordedat >= :rec_at_from')->setParameter('rec_at_from', $from);
        $this->queryBuilder->andWhere('recordedat <= :rec_at_to')->setParameter('rec_at_to', $to);
        return $this;
    }

    public function whereSequenceNumberBetween(int $from, int $to, int $context = 0, int $contextBefore = 0, int $contextAfter = 0): self
    {
        $this->queryBuilder->setParameter('sequencenumber_ctx_before', $contextBefore ?: $context);
        $this->queryBuilder->setParameter('sequencenumber_ctx_after', $contextAfter ?: $context);

        $this->queryBuilder->andWhere('sequencenumber >= :sequencenumber_from - :sequencenumber_ctx_before')
            ->setParameter('sequencenumber_from', $from);

        $this->queryBuilder->andWhere('sequencenumber <= :sequencenumber_to + :sequencenumber_ctx_after')
            ->setParameter('sequencenumber_to', $to);

        // Add marker if there's any context
        if ($context || $contextBefore || $contextAfter) {
            $this->queryBuilder->addSelect("CASE 
            WHEN sequencenumber >= :sequencenumber_from AND sequencenumber <= :sequencenumber_to THEN 'XXX'
            ELSE ''
        END AS mrk");
        }

        return $this;
    }

    public function whereStreamNotLike(string $notLike): self
    {
        $this->queryBuilder->andWhere('stream NOT LIKE :stream_not_like')->setParameter('stream_not_like', $notLike);
        return $this;
    }

    public function whereType(string ...$values): self
    {
        $values = array_map(
            EventFilter::eventClassNameToShortName(...),
            $values
        );

        return $this->buildWhereInClause('type', $values);
    }

    public function whereStream(string ...$values)
    {
        return $this->buildWhereInClause('stream', $values);
    }

    private function buildWhereInClause(string $column, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        // Build the IN clause with parameterized values
        $placeholders = [];
        foreach ($values as $index => $value) {
            $paramName = $column . '_' . $index;
            $placeholders[] = ':' . $paramName;
            $this->queryBuilder->setParameter($paramName, $value);
        }

        $this->queryBuilder->andWhere(
            $column . ' IN (' . implode(', ', $placeholders) . ')'
        );
        return $this;
    }

    public function groupByMonth(): self
    {
        $this->queryBuilder
            ->addGroupBy("DATE_FORMAT(recordedat,'%Y-%m')")
            ->addSelect("DATE_FORMAT(recordedat,'%Y-%m') as month")
            ->addOrderBy('month');
        return $this;
    }

    public function groupByDay(): self
    {
        $this->queryBuilder
            ->addGroupBy("DATE_FORMAT(recordedat,'%Y-%m-%d')")
            ->addSelect("DATE_FORMAT(recordedat,'%Y-%m-%d') as day")
            ->addOrderBy('day');
        return $this;
    }

    public function groupByType(): self
    {
        $this->queryBuilder
            ->addGroupBy('type')
            ->addSelect('type')
            ->addOrderBy('type');
        return $this;
    }

    public function groupByStream(): self
    {
        $this->queryBuilder
            ->addGroupBy('stream')
            ->addSelect('stream')
            ->addOrderBy('stream');
        return $this;
    }

    public function count(): self
    {
        $this->queryBuilder->addSelect('COUNT(*) as count');
        return $this;
    }

    public function execute(): Result
    {
        return $this->queryBuilder->executeQuery();
    }

    public function recordedAtMinMax()
    {
        $this->queryBuilder
            ->addSelect('MIN(recordedat) as recordedat_min')
            ->addSelect('MAX(recordedat) as recordedat_max')
            ->addSelect('MAX(recordedat)-MIN(recordedat) as recordedat_diff')
            ->addOrderBy('recordedat_min');
        return $this;
    }

    public function sequenceNumberMinMax()
    {
        $this->queryBuilder
            ->addSelect('MIN(sequencenumber) as sequencenumber_min')
            ->addSelect('MAX(sequencenumber) as sequencenumber_max')
            ->addSelect('MAX(sequencenumber)-MIN(sequencenumber) as sequencenumber_diff')
            ->addOrderBy('sequencenumber_min');
        return $this;
    }

    public function select(string ...$select): self
    {
        $this->queryBuilder->select(...$select);
        return $this;
    }
}
