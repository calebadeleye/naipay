<?php

declare(strict_types=1);

namespace App\Support\Query;

/**
 * How a permitted filter key is translated into a query constraint.
 */
enum FilterType: string
{
    /** Exact match. `?status=active` */
    case Exact = 'exact';

    /** Match any of a comma-separated list. `?status=active,dormant` */
    case In = 'in';

    /** Case-insensitive containment. `?business_name=grace` */
    case Partial = 'partial';

    /** Boolean, accepting true/false/1/0/yes/no. */
    case Boolean = 'boolean';

    /**
     * Inclusive date range against a date or datetime column, driven by
     * `<key>_from` and `<key>_to`. A datetime column is compared by calendar
     * day so `?payment_date_to=2026-05-01` includes everything on that day.
     */
    case DateRange = 'date_range';

    /**
     * Inclusive numeric range driven by `<key>_min` and `<key>_max`. Used for
     * monetary columns, where the bound is given as a decimal string.
     */
    case AmountRange = 'amount_range';

    /** Presence or absence of a value. `?approved_at=null` / `?approved_at=notnull` */
    case NullState = 'null_state';
}
