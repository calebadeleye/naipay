<?php

declare(strict_types=1);

namespace App\Domains\Loans\Models;

use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Branches\Concerns\BelongsToBranch;
use App\Domains\Businesses\Models\Business;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Models\JournalTransaction;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A credit facility: the record that exists once a loan application is
 * approved, before and after money actually moves.
 *
 * @property int $id
 * @property string $loan_reference
 * @property LoanStatus $status
 * @property Money $principal_amount
 * @property InterestMethod $interest_method
 * @property RepaymentFrequency $repayment_frequency
 * @property int $tenor
 */
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $table = 'loans';

    /**
     * Terms, custody and every balance field are set exclusively by
     * LoanCreationService and LoanDisbursementService, never by mass
     * assignment from a request — the same discipline as BankAccount's
     * default flags.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_application_id',
        'merchant_id',
        'business_id',
        'loan_product_id',
        'branch_id',
        'principal_amount',
        'interest_method',
        'interest_rate',
        'repayment_frequency',
        'tenor',
        'grace_period_days',
    ];

    /**
     * @return BelongsTo<LoanApplication, $this>
     */
    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<LoanProduct, $this>
     */
    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function disbursementBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'disbursement_bank_account_id');
    }

    /**
     * @return BelongsTo<JournalTransaction, $this>
     */
    public function disbursementJournalTransaction(): BelongsTo
    {
        return $this->belongsTo(JournalTransaction::class, 'disbursement_journal_transaction_id');
    }

    /**
     * @return HasMany<LoanScheduleEntry, $this>
     */
    public function scheduleEntries(): HasMany
    {
        return $this->hasMany(LoanScheduleEntry::class)->orderBy('installment_number');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'disbursed_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function writtenOffBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'written_off_by');
    }

    public function isDisbursed(): bool
    {
        return $this->status === LoanStatus::Disbursed;
    }

    /**
     * Whether the merchant owes nothing further on this loan — every
     * principal, interest and fee cent contracted has been collected.
     * Status-based checks (`isOutstanding()`) can't tell this apart from a
     * loan still mid-repayment, since there is no terminal "paid off" status
     * in `LoanStatus`; this reads the cached balances instead.
     */
    public function isFullyPaid(): bool
    {
        return $this->status === LoanStatus::Disbursed
            && $this->outstanding_principal !== null
            && $this->outstanding_principal->isZero()
            && $this->outstanding_interest->isZero()
            && $this->outstanding_fees->isZero();
    }

    /**
     * Principal + interest + fees collected so far. Derived from the cached
     * totals/outstanding balances rather than re-summing repayments, so it
     * stays consistent with `outstanding_*` and the schedule.
     */
    public function totalPaid(): ?Money
    {
        if ($this->total_payable === null) {
            return null;
        }

        return $this->total_payable->minus(
            $this->outstanding_principal
                ->plus($this->outstanding_interest)
                ->plus($this->outstanding_fees),
        );
    }

    public function principalPaid(): ?Money
    {
        if ($this->outstanding_principal === null) {
            return null;
        }

        return $this->principal_amount->minus($this->outstanding_principal);
    }

    public function interestPaid(): ?Money
    {
        if ($this->total_interest === null || $this->outstanding_interest === null) {
            return null;
        }

        return $this->total_interest->minus($this->outstanding_interest);
    }

    public function feesPaid(): ?Money
    {
        if ($this->total_fees === null || $this->outstanding_fees === null) {
            return null;
        }

        return $this->total_fees->minus($this->outstanding_fees);
    }

    /**
     * Loans whose borrower is assigned to the given staff member — the same
     * loan-officer relationship the portfolio analytics use
     * (`merchants.assigned_officer_id`), not whoever keyed the loan in.
     *
     * @param  Builder<Loan>  $query
     */
    public function scopeForOfficer(Builder $query, int $staffId): void
    {
        $query->whereIn(
            'merchant_id',
            Merchant::query()->where('assigned_officer_id', $staffId)->select('id'),
        );
    }

    /**
     * Loans carrying at least one instalment that fell due before `$asOf`
     * (default today) and is not fully paid. `$minDays` / `$maxDays` bound how
     * far past due that earliest breach is, so a caller can ask for "30+ days"
     * (PAR) or "8–30 days" (an ageing bucket).
     *
     * Arrears are read from the schedule, never inferred from a loan balance —
     * the same rule the analytics service follows.
     *
     * @param  Builder<Loan>  $query
     */
    public function scopeInArrears(Builder $query, ?int $minDays = null, ?int $maxDays = null, ?Carbon $asOf = null): void
    {
        $date = ($asOf ?? Carbon::today())->toDateString();

        $query->whereHas('scheduleEntries', function (Builder $entry) use ($date, $minDays, $maxDays): void {
            $entry->whereDate('due_date', '<', $date)
                ->whereRaw('(principal_paid + interest_paid + fee_paid) < (principal_due + interest_due + fee_due)');

            if ($minDays !== null) {
                $entry->whereRaw('DATEDIFF(?, due_date) >= ?', [$date, $minDays]);
            }

            if ($maxDays !== null) {
                $entry->whereRaw('DATEDIFF(?, due_date) <= ?', [$date, $maxDays]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'interest_method' => InterestMethod::class,
            'repayment_frequency' => RepaymentFrequency::class,
            'principal_amount' => MoneyCast::class,
            'total_interest' => MoneyCast::class,
            'total_fees' => MoneyCast::class,
            'total_payable' => MoneyCast::class,
            'outstanding_principal' => MoneyCast::class,
            'outstanding_interest' => MoneyCast::class,
            'outstanding_fees' => MoneyCast::class,
            'tenor' => 'integer',
            'grace_period_days' => 'integer',
            'disbursement_date' => 'date',
            'first_repayment_date' => 'date',
            'maturity_date' => 'date',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'written_off_at' => 'datetime',
        ];
    }
}
