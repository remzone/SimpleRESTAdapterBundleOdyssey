<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Search\Query;

final readonly class TermQuery
{
    public function __construct(
        private string $field,
        private mixed $value,
    ) {
    }

    public function toArray(): array
    {
        return [
            'term' => [
                $this->field => $this->value,
            ],
        ];
    }
}
