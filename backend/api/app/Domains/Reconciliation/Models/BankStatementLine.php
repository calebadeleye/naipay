<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Models;

use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Identity\Models\Staff;
use App\Domains\Loans\Models\Loan;
use App\Domains\Reconciliation\Enums\BankStatementLineDirection;
use App\Domains\Reconciliation\Enums\BankStatementLineStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Support\Money\MoneyCast;
use Database\Factories\BankStatementLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bank statement, transcribed by an officer.
 *
 * @property int $id
 * @property BankStatementLineStatus $status
 * @property ?string $matched_to_type
 * @property ?int $matched_to_id
 */
class BankStatementLine extends Model
{
    /** @use HasFactory<BankStatementLineFactory> */
    use HasFactory;

    protected $table = 'bank_statement_lines';

    /**
     * Matching, exclusion and every field past initial entry are set
     * exclusively by ReconciliationService, never by mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'bank_reconciliation_id',
        'bank_account_id',
        'statement_date',
        'description',
        'external_reference',
        'amount',
        'direction',
    ];

    /**
     * @return BelongsTo<BankReconciliation, $this>
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'matched_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * The internal record this line was matched to.
     *
     * Resolved directly rather than through Eloquent's morph map: exactly
     * two record types can ever appear here, and a plain match expression is
     * clearer than configuring a map for it.
     */
    public function matchedRecord(): Repayment|Loan|null
    {
        return match ($this->matched_to_type) {
            Repayment::class => Repayment::query()->find($this->matched_to_id),
            Loan::class => Loan::query()->find($this->matched_to_id),
            default => null,
        };
    }

    public function isMatched(): bool
    {
        return $this->status === BankStatementLineStatus::Matched;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BankStatementLineStatus::class,
            'direction' => BankStatementLineDirection::class,
            'statement_date' => 'date',
            'amount' => MoneyCast::class,
            'matched_at' => 'datetime',
        ];
    }
}
