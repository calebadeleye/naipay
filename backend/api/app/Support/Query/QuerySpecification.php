<?php

declare(strict_types=1);

namespace App\Support\Query;

/**
 * Declares what a given list endpoint permits a client to search, filter and
 * sort by.
 *
 * Everything is allow-listed. A filter or sort column that is not named here is
 * ignored rather than passed to the query builder, so no request can order by
 * or filter on a column it was never meant to reach — `bvn`, say, or another
 * branch's records.
 */
final class QuerySpecification
{
    /**
     * @param  array<int, string>  $searchable  Columns swept by the `search` term. Dot
     *                                          notation traverses a relation: `merchant.last_name`.
     * @param  array<string, FilterType>  $filters  Permitted filter keys and how each is applied.
     * @param  array<int, string>  $sortable  Permitted sort columns.
     * @param  array<int, string>  $defaultSort  Applied when the client requests no order.
     *                                           Prefix with `-` for descending.
     */
    public function __construct(
        public readonly array $searchable = [],
        public readonly array $filters = [],
        public readonly array $sortable = [],
        public readonly array $defaultSort = ['-created_at'],
    ) {}

    /**
     * @param  array<int, string>  $searchable
     * @param  array<string, FilterType>  $filters
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $defaultSort
     */
    public static function make(
        array $searchable = [],
        array $filters = [],
        array $sortable = [],
        array $defaultSort = ['-created_at'],
    ): self {
        return new self($searchable, $filters, $sortable, $defaultSort);
    }
}
