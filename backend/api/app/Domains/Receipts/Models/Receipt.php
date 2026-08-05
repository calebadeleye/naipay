<?php

declare(strict_types=1);

namespace App\Domains\Receipts\Models;

use App\Domains\Businesses\Models\Business;
use App\Domains\Loans\Models\Loan;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Repayments\Models\Repayment;
use App\Support\Money\MoneyCast;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $receipt_number
 */
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    protected $table = 'receipts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'repayment_id',
        'merchant_id',
        'business_id',
        'loan_id',
        'amount',
        'issued_at',
    ];

    /**
     * @return BelongsTo<Repayment, $this>
     */
    public function repayment(): BelongsTo
    {
        return $this->belongsTo(Repayment::class);
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
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'issued_at' => 'datetime',
        ];
    }
}
