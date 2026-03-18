<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Search\Query;

final readonly class SimpleQueryStringQuery
{
    public function __construct(
        private string $query,
    ) {
    }

    public function toArray(): array
    {
        return [
            'simple_query_string' => [
                'query' => $this->query,
            ],
        ];
    }
}
