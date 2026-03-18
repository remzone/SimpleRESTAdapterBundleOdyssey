<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Search\Sort;

final readonly class FieldSort
{
    public function __construct(
        private string $field,
        private string $direction = 'asc',
    ) {
    }

    public function toArray(): array
    {
        return [
            $this->field => [
                'order' => $this->direction,
            ],
        ];
    }
}
