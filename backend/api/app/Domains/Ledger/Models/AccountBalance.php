<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cached running balance for one ledger account.
 *
 * Maintained exclusively by LedgerPostingService under a row lock. Nothing
 * else writes to this table — see the class docblock on the migration for why
 * it is a cache and not the source of truth.
 *
 * @property int $ledger_account_id
 * @property Money $total_debits
 * @property Money $total_credits
 * @property Money $balance
 */
class AccountBalance extends Model
{
    protected $table = 'account_balances';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ledger_account_id',
        'total_debits',
        'total_credits',
        'balance',
        'last_journal_transaction_id',
        'updated_at',
    ];

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_debits' => MoneyCast::class,
            'total_credits' => MoneyCast::class,
            'balance' => MoneyCast::class,
            'updated_at' => 'datetime',
        ];
    }
}
