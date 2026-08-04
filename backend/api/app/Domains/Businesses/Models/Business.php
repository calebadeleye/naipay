<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Models;

use App\Domains\Businesses\Enums\BusinessStatus;
use App\Domains\Businesses\Enums\BusinessType;
use App\Domains\Businesses\Enums\VerificationStatus;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A business operated by a merchant.
 *
 * @property int $id
 * @property string $business_number
 * @property int $merchant_id
 * @property string $business_name
 * @property BusinessType $business_type
 * @property BusinessStatus $status
 * @property VerificationStatus $verification_status
 * @property Money|null $estimated_monthly_revenue
 * @property Money|null $estimated_monthly_expenses
 */
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'businesses';

    /**
     * The business-connection fields are deliberately absent. They belong to a
     * future phase, must never be enabled without the merchant's explicit
     * consent, and leaving them out of mass assignment means no request payload
     * can switch a merchant's shop into a public directory.
     *
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'business_category_id',
        'business_subcategory_id',
        'business_name',
        'registered_business_name',
        'cac_registration_number',
        'business_type',
        'business_description',
        'business_phone',
        'business_email',
        'business_address',
        'city',
        'state',
        'country',
        'business_website',
        'social_media_links',
        'gps_latitude',
        'gps_longitude',
        'year_established',
        'number_of_employees',
        'estimated_monthly_revenue',
        'estimated_monthly_expenses',
        'average_monthly_sales',
    ];

    // --- Relationships -----------------------------------------------------

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<BusinessCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'business_category_id');
    }

    /**
     * @return BelongsTo<BusinessCategory, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'business_subcategory_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    // --- Trading profile ---------------------------------------------------

    /**
     * Declared monthly surplus: revenue less expenses.
     *
     * The starting point for repayment-capacity assessment. Null when either
     * side is unknown — an assumed zero would silently understate or overstate
     * affordability, and "we did not ask" is a different answer from "there is
     * none".
     */
    public function declaredMonthlySurplus(): ?Money
    {
        if ($this->estimated_monthly_revenue === null || $this->estimated_monthly_expenses === null) {
            return null;
        }

        return $this->estimated_monthly_revenue->minus($this->estimated_monthly_expenses);
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    public function isVerified(): bool
    {
        return $this->verification_status->isVerified();
    }

    /**
     * Whether this business still owes a CAC registration number.
     */
    public function hasOutstandingRegistration(): bool
    {
        return $this->business_type->requiresCacRegistration()
            && ($this->cac_registration_number === null || $this->cac_registration_number === '');
    }

    /**
     * Restricts to businesses whose merchant the given branch scope can reach.
     *
     * Businesses carry no branch of their own — they belong to a merchant, and
     * the merchant belongs to a branch — so the scope is applied through the
     * relationship rather than duplicating branch_id here and risking the two
     * drifting apart.
     *
     * @param  Builder<Business>  $query
     */
    public function scopeVisibleTo(Builder $query, Staff $staff): void
    {
        $query->whereHas('merchant', fn (Builder $merchant) => $merchant->visibleTo($staff));
    }

    /**
     * @param  Builder<Business>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', BusinessStatus::Active->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => BusinessStatus::class,
            'verification_status' => VerificationStatus::class,
            'social_media_links' => 'array',
            'connection_preferences' => 'array',
            'service_areas' => 'array',
            'products_and_services' => 'array',
            'public_profile_enabled' => 'boolean',
            'accepts_business_connections' => 'boolean',
            'estimated_monthly_revenue' => MoneyCast::class,
            'estimated_monthly_expenses' => MoneyCast::class,
            'average_monthly_sales' => MoneyCast::class,
            'approved_at' => 'datetime',
        ];
    }
}
