<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Support\Money\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Applies search, filtering, sorting and pagination to a list query from the
 * request, within the bounds a QuerySpecification allows.
 *
 * Every administrative table in Naipay routes through here, so paging and
 * filtering behave identically across merchants, loans, repayments and reports,
 * and the merchant portal will inherit the same semantics for free.
 */
final class QueryPipeline
{
    public function __construct(
        private readonly Request $request,
        private readonly QuerySpecification $specification,
    ) {}

    public static function for(Request $request, QuerySpecification $specification): self
    {
        return new self($request, $specification);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        $this->applySearch($query);
        $this->applyFilters($query);
        $this->applySorting($query);

        return $query;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(Builder $query): LengthAwarePaginator
    {
        return $this->apply($query)
            ->paginate($this->perPage())
            ->withQueryString();
    }

    public function perPage(): int
    {
        $default = (int) config('naipay.pagination.default_per_page', 25);
        $max = (int) config('naipay.pagination.max_per_page', 200);

        $requested = (int) $this->request->input('per_page', $default);

        if ($requested < 1) {
            return $default;
        }

        // Clamped rather than rejected: an oversized page is a client bug, not
        // a reason to fail an operator's screen. The ceiling is what protects
        // the database.
        return min($requested, $max);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applySearch(Builder $query): void
    {
        $term = trim((string) $this->request->input('search', ''));

        if ($term === '' || $this->specification->searchable === []) {
            return;
        }

        // Escape the LIKE wildcards so a search for "50%" is a literal search
        // and not a match against everything.
        $escaped = addcslashes($term, '%_\\');
        $pattern = "%{$escaped}%";

        $query->where(function (Builder $group) use ($pattern): void {
            foreach ($this->specification->searchable as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $relatedColumn] = $this->splitRelation($column);

                    $group->orWhereHas(
                        $relation,
                        fn (Builder $related) => $related->where($relatedColumn, 'like', $pattern),
                    );

                    continue;
                }

                $group->orWhere($column, 'like', $pattern);
            }
        });
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyFilters(Builder $query): void
    {
        foreach ($this->specification->filters as $key => $type) {
            match ($type) {
                FilterType::Exact => $this->applyExact($query, $key),
                FilterType::In => $this->applyIn($query, $key),
                FilterType::Partial => $this->applyPartial($query, $key),
                FilterType::Boolean => $this->applyBoolean($query, $key),
                FilterType::DateRange => $this->applyDateRange($query, $key),
                FilterType::AmountRange => $this->applyAmountRange($query, $key),
                FilterType::NullState => $this->applyNullState($query, $key),
            };
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyExact(Builder $query, string $key): void
    {
        if (! $this->present($key)) {
            return;
        }

        $query->where($this->column($key), $this->request->input($key));
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyIn(Builder $query, string $key): void
    {
        if (! $this->present($key)) {
            return;
        }

        $raw = $this->request->input($key);

        $values = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($raw) ? $raw : explode(',', (string) $raw),
            ),
            static fn (string $value): bool => $value !== '',
        ));

        if ($values === []) {
            return;
        }

        $query->whereIn($this->column($key), $values);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyPartial(Builder $query, string $key): void
    {
        if (! $this->present($key)) {
            return;
        }

        $escaped = addcslashes(trim((string) $this->request->input($key)), '%_\\');

        $query->where($this->column($key), 'like', "%{$escaped}%");
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyBoolean(Builder $query, string $key): void
    {
        if (! $this->present($key)) {
            return;
        }

        $value = filter_var($this->request->input($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            return;
        }

        $query->where($this->column($key), $value);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyDateRange(Builder $query, string $key): void
    {
        $column = $this->column($key);

        // Compared by calendar day so an inclusive `_to` bound covers the whole
        // of that day even on a datetime column.
        if ($this->present("{$key}_from") && ($from = $this->parseDate("{$key}_from")) !== null) {
            $query->whereDate($column, '>=', $from);
        }

        if ($this->present("{$key}_to") && ($to = $this->parseDate("{$key}_to")) !== null) {
            $query->whereDate($column, '<=', $to);
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyAmountRange(Builder $query, string $key): void
    {
        $column = $this->column($key);

        foreach ([['_min', '>='], ['_max', '<=']] as [$suffix, $operator]) {
            if (! $this->present($key.$suffix)) {
                continue;
            }

            try {
                // Round-tripped through Money so a malformed bound is rejected
                // and the comparison is made against an exact decimal.
                $bound = Money::fromDecimal((string) $this->request->input($key.$suffix))->toDecimalString();
            } catch (InvalidArgumentException) {
                continue;
            }

            $query->where($column, $operator, $bound);
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyNullState(Builder $query, string $key): void
    {
        if (! $this->present($key)) {
            return;
        }

        $state = mb_strtolower(trim((string) $this->request->input($key)));

        match ($state) {
            'null', 'empty', 'none' => $query->whereNull($this->column($key)),
            'notnull', 'present', 'any' => $query->whereNotNull($this->column($key)),
            default => null,
        };
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applySorting(Builder $query): void
    {
        $requested = trim((string) $this->request->input('sort', ''));

        $columns = $requested !== ''
            ? array_map(trim(...), explode(',', $requested))
            : $this->specification->defaultSort;

        $applied = false;

        foreach ($columns as $column) {
            $descending = str_starts_with($column, '-');
            $name = ltrim($column, '-+');

            // Silently skipped rather than rejected: an unknown sort column
            // should not break a screen, and honouring it would expose columns
            // the endpoint never intended to order by.
            if ($name === '' || ! in_array($name, $this->specification->sortable, true)) {
                continue;
            }

            $query->orderBy($name, $descending ? 'desc' : 'asc');
            $applied = true;
        }

        if (! $applied) {
            $this->applyDefaultSort($query);
        }

        // Without a deterministic tiebreaker, rows with equal sort values can
        // appear on two pages or none as the client walks the result set.
        $model = $query->getModel();

        if ($model->getKeyName() !== null) {
            $query->orderBy($model->qualifyColumn($model->getKeyName()), 'desc');
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyDefaultSort(Builder $query): void
    {
        foreach ($this->specification->defaultSort as $column) {
            $descending = str_starts_with($column, '-');
            $name = ltrim($column, '-+');

            if ($name !== '') {
                $query->orderBy($name, $descending ? 'desc' : 'asc');
            }
        }
    }

    private function parseDate(string $key): ?Carbon
    {
        try {
            return Carbon::parse((string) $this->request->input($key));
        } catch (Throwable) {
            // An unparseable date narrows nothing rather than erroring the
            // whole screen.
            return null;
        }
    }

    /**
     * Filter keys are snake_case and map directly to columns; the indirection
     * exists so a key can later diverge from its column name.
     */
    private function column(string $key): string
    {
        return Str::snake($key);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRelation(string $path): array
    {
        $segments = explode('.', $path);
        $column = array_pop($segments);

        return [implode('.', $segments), $column];
    }

    private function present(string $key): bool
    {
        $value = $this->request->input($key);

        return $value !== null && $value !== '' && $value !== [];
    }
}
