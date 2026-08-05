<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Domains\Ledger\Enums\AccountType;
use App\Support\Money\Money;
use Database\Factories\LedgerAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An account in the chart of accounts.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property bool $is_system
 */
class LedgerAccount extends Model
{
    /** @use HasFactory<LedgerAccountFactory> */
    use HasFactory;

    protected $table = 'ledger_accounts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'is_system',
        'status',
    ];

    /**
     * @return HasOne<AccountBalance, $this>
     */
    public function balance(): HasOne
    {
        return $this->hasOne(AccountBalance::class);
    }

    /**
     * @param  Builder<LedgerAccount>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The current balance, signed the way this account's type normally reads
     * — positive for an asset or expense with money in it, positive for a
     * liability, equity or income account with money owed or earned.
     */
    public function currentBalance(): Money
    {
        $stored = $this->balance?->balance ?? Money::zero();

        // account_balances always stores debits-minus-credits; a credit-normal
        // account's healthy balance is therefore stored as a negative number,
        // and is inverted here so the figure reads the way an accountant
        // expects it to.
        return $this->type->normalBalance()->value === 'credit' ? $stored->negated() : $stored;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_system' => 'boolean',
        ];
    }
}
