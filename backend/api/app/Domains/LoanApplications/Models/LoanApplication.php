<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Models;

use App\Domains\Branches\Concerns\BelongsToBranch;
use App\Domains\Businesses\Models\Business;
use App\Domains\Identity\Models\Staff;
use App\Domains\LoanApplications\Enums\LoanApplicationStatus;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Money\MoneyCast;
use Database\Factories\LoanApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A merchant's request to borrow against a loan product.
 *
 * @property int $id
 * @property string $application_number
 * @property LoanApplicationStatus $status
 */
class LoanApplication extends Model
{
    /** @use HasFactory<LoanApplicationFactory> */
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $table = 'loan_applications';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'business_id',
        'loan_product_id',
        'branch_id',
        'requested_amount',
        'requested_tenor',
        'purpose',
    ];

    // --- Relationships -------------------------------------------------------

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
     * @return HasMany<Guarantor, $this>
     */
    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
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
    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assessed_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function recommendedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recommended_by');
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
    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'withdrawn_by');
    }

    // --- Behaviour -------------------------------------------------------------

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isPendingDecision(): bool
    {
        return $this->status->isPendingDecision();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this application already has as many guarantors as its product
     * requires. Always true for a product that requires none.
     */
    public function hasSufficientGuarantors(): bool
    {
        $product = $this->loanProduct;

        if ($product === null || ! $product->requires_guarantor) {
            return true;
        }

        return $this->guarantors()->count() >= $product->minimum_guarantors;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoanApplicationStatus::class,
            'requested_amount' => MoneyCast::class,
            'approved_amount' => MoneyCast::class,
            'requested_tenor' => 'integer',
            'approved_tenor' => 'integer',
            'assessed_at' => 'datetime',
            'recommended_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'submitted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
