<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Models;

use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Identity\Models\Staff;
use App\Domains\Reconciliation\Enums\BankReconciliationStatus;
use App\Domains\Reconciliation\Enums\BankStatementLineStatus;
use App\Support\Money\MoneyCast;
use Database\Factories\BankReconciliationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property BankReconciliationStatus $status
 */
class BankReconciliation extends Model
{
    /** @use HasFactory<BankReconciliationFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'bank_reconciliations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bank_account_id',
        'period_start',
        'period_end',
        'statement_opening_balance',
        'statement_closing_balance',
        'notes',
    ];

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }

    public function hasUnresolvedLines(): bool
    {
        return $this->lines()->where('status', BankStatementLineStatus::Unmatched->value)->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BankReconciliationStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'statement_opening_balance' => MoneyCast::class,
            'statement_closing_balance' => MoneyCast::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
