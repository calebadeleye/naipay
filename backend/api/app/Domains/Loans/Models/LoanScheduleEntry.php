<?php

declare(strict_types=1);

namespace App\Domains\Loans\Models;

use App\Domains\Loans\Enums\LoanScheduleEntryStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\LoanScheduleEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instalment of a loan's repayment schedule.
 *
 * @property int $id
 * @property int $installment_number
 * @property Money $principal_due
 * @property Money $interest_due
 * @property Money $fee_due
 */
class LoanScheduleEntry extends Model
{
    /** @use HasFactory<LoanScheduleEntryFactory> */
    use HasFactory;

    protected $table = 'loan_schedule_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'installment_number',
        'due_date',
        'opening_principal',
        'principal_due',
        'interest_due',
        'fee_due',
        'principal_paid',
        'interest_paid',
        'fee_paid',
        'status',
    ];

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function totalDue(): Money
    {
        return $this->principal_due->plus($this->interest_due)->plus($this->fee_due);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'opening_principal' => MoneyCast::class,
            'principal_due' => MoneyCast::class,
            'interest_due' => MoneyCast::class,
            'fee_due' => MoneyCast::class,
            'principal_paid' => MoneyCast::class,
            'interest_paid' => MoneyCast::class,
            'fee_paid' => MoneyCast::class,
            'status' => LoanScheduleEntryStatus::class,
            'installment_number' => 'integer',
        ];
    }
}
