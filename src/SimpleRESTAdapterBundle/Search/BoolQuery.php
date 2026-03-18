<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Search;

final class BoolQuery
{
    public const MUST = 'must';
    public const MUST_NOT = 'must_not';
    public const SHOULD = 'should';

    private function __construct()
    {
    }
}
