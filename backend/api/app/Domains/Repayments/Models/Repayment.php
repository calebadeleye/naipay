<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Models;

use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Branches\Concerns\BelongsToBranch;
use App\Domains\Businesses\Models\Business;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Models\JournalTransaction;
use App\Domains\Loans\Models\Loan;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Repayments\Enums\PaymentMethod;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Support\Money\MoneyCast;
use Database\Factories\RepaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A manually recorded repayment against a loan.
 *
 * @property int $id
 * @property string $repayment_reference
 * @property RepaymentStatus $status
 */
class Repayment extends Model
{
    /** @use HasFactory<RepaymentFactory> */
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $table = 'repayments';

    /**
     * Everything past recording — verification, approval, allocation,
     * reversal — is set exclusively by RepaymentService, never by mass
     * assignment from a request.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'merchant_id',
        'business_id',
        'branch_id',
        'receiving_bank_account_id',
        'amount',
        'payment_date',
        'payment_method',
        'sender_account_name',
        'sender_bank_name',
        'bank_reference',
        'notes',
    ];

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
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
     * @return BelongsTo<BankAccount, $this>
     */
    public function receivingBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'receiving_bank_account_id');
    }

    /**
     * @return HasMany<RepaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(RepaymentAllocation::class);
    }

    /**
     * @return BelongsTo<JournalTransaction, $this>
     */
    public function repaymentJournalTransaction(): BelongsTo
    {
        return $this->belongsTo(JournalTransaction::class, 'repayment_journal_transaction_id');
    }

    /**
     * @return BelongsTo<JournalTransaction, $this>
     */
    public function reversalJournalTransaction(): BelongsTo
    {
        return $this->belongsTo(JournalTransaction::class, 'reversal_journal_transaction_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'verified_by');
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
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'rejected_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reversed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RepaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount' => MoneyCast::class,
            'allocated_fee' => MoneyCast::class,
            'allocated_interest' => MoneyCast::class,
            'allocated_principal' => MoneyCast::class,
            'allocated_excess' => MoneyCast::class,
            'allocated_unallocated' => MoneyCast::class,
            'payment_date' => 'date',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }
}
