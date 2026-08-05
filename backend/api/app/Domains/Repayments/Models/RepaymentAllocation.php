<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Models;

use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instalment's share of an approved repayment.
 *
 * @property int $repayment_id
 * @property int $loan_schedule_entry_id
 */
class RepaymentAllocation extends Model
{
    public $timestamps = false;

    protected $table = 'repayment_allocations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'repayment_id',
        'loan_schedule_entry_id',
        'principal_amount',
        'interest_amount',
        'fee_amount',
    ];

    /**
     * @return BelongsTo<Repayment, $this>
     */
    public function repayment(): BelongsTo
    {
        return $this->belongsTo(Repayment::class);
    }

    /**
     * @return BelongsTo<LoanScheduleEntry, $this>
     */
    public function scheduleEntry(): BelongsTo
    {
        return $this->belongsTo(LoanScheduleEntry::class, 'loan_schedule_entry_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal_amount' => MoneyCast::class,
            'interest_amount' => MoneyCast::class,
            'fee_amount' => MoneyCast::class,
            'created_at' => 'datetime',
        ];
    }
}
