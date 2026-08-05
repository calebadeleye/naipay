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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
