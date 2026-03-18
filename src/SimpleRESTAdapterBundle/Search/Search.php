<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Search;

use CIHub\Bundle\SimpleRESTAdapterBundle\Search\Aggregation\TermsAggregation;
use CIHub\Bundle\SimpleRESTAdapterBundle\Search\Sort\FieldSort;

final class Search
{
    private int $size = 10;

    private int $from = 0;

    private array $sort = [];

    private array $query = [];

    private array $aggregations = [];

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    public function setFrom(int $from): void
    {
        $this->from = $from;
    }

    public function addSort(FieldSort $sort): void
    {
        $this->sort[] = $sort->toArray();
    }

    public function addQuery(object $query, string $operator = BoolQuery::MUST): void
    {
        if (!method_exists($query, 'toArray')) {
            return;
        }

        $this->query[$operator] ??= [];
        $this->query[$operator][] = $query->toArray();
    }

    public function addAggregation(TermsAggregation $aggregation): void
    {
        $this->aggregations[$aggregation->getName()] = $aggregation->toArray();
    }

    public function toArray(): array
    {
        $result = [
            'from' => $this->from,
            'size' => $this->size,
        ];

        if (!empty($this->query)) {
            $result['query'] = [
                'bool' => $this->query,
            ];
        }

        if (!empty($this->sort)) {
            $result['sort'] = $this->sort;
        }

        if (!empty($this->aggregations)) {
            $result['aggs'] = $this->aggregations;
        }

        return $result;
    }
}
