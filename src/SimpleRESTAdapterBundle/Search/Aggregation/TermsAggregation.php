<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Search\Aggregation;

final readonly class TermsAggregation
{
    public function __construct(
        private string $name,
        private string $field,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function toArray(): array
    {
        return [
            'terms' => [
                'field' => $this->field,
            ],
        ];
    }
}
