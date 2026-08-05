<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Models;

use App\Domains\Accounts\Enums\AccountStatus;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\MerchantAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant's internal account.
 *
 * @property int $id
 * @property string $account_number
 * @property string $account_name
 * @property AccountStatus $status
 * @property Money $available_balance
 * @property Money $ledger_balance
 */
class MerchantAccount extends Model
{
    /** @use HasFactory<MerchantAccountFactory> */
    use HasFactory;

    protected $table = 'merchant_accounts';

    /**
     * Balances are absent from mass assignment on purpose: they move only
     * through the ledger, never through a request payload.
     *
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'account_name',
        'account_type',
        'currency',
    ];

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Groups the account number for display: 0123 456 789.
     *
     * Kept as a presentation concern only — the stored value is always the
     * bare ten digits, so a copied-and-pasted number matches.
     */
    public function formattedAccountNumber(): string
    {
        return trim(chunk_split($this->account_number, 4, ' '));
    }

    public function canTransact(): bool
    {
        return $this->status->canTransact();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'available_balance' => MoneyCast::class.':currency',
            'ledger_balance' => MoneyCast::class.':currency',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'balances_reconciled_at' => 'datetime',
        ];
    }
}
