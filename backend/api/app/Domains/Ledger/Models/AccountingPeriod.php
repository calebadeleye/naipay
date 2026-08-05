<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Enums\AccountingPeriodStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $status
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
class AccountingPeriod extends Model
{
    protected $table = 'accounting_periods';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'starts_on',
        'ends_on',
    ];

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === AccountingPeriodStatus::Closed;
    }

    public function covers(Carbon $date): bool
    {
        return $date->betweenIncluded($this->starts_on, $this->ends_on);
    }

    /**
     * @param  Builder<AccountingPeriod>  $query
     */
    public function scopeCovering(Builder $query, Carbon $date): void
    {
        $query->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountingPeriodStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }
}
