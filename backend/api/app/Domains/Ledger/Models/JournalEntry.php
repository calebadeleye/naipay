<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One debit or credit line of a journal transaction.
 *
 * Immutable, like its parent — see JournalTransaction::booted(). A journal
 * entry is never updated or deleted once its transaction is posted; the
 * database's own CHECK constraint additionally refuses a row that carries
 * both a debit and a credit, or neither.
 *
 * @property int $journal_transaction_id
 * @property int $ledger_account_id
 * @property Money $debit_amount
 * @property Money $credit_amount
 */
class JournalEntry extends Model
{
    public $timestamps = false;

    protected $table = 'journal_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'journal_transaction_id',
        'ledger_account_id',
        'debit_amount',
        'credit_amount',
        'description',
        'merchant_id',
        'business_id',
        'loan_id',
        'branch_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Journal entries are immutable once posted. Post a reversal instead.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Journal entries are permanent and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<JournalTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(JournalTransaction::class, 'journal_transaction_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function isDebit(): bool
    {
        return $this->debit_amount->isPositive();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_amount' => MoneyCast::class,
            'credit_amount' => MoneyCast::class,
            'created_at' => 'datetime',
        ];
    }
}
