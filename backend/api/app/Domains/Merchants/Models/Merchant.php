<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Models;

use App\Domains\Accounts\Models\MerchantAccount;
use App\Domains\Branches\Concerns\BelongsToBranch;
use App\Domains\Businesses\Models\Business;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Enums\KycStatus;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Enums\OnboardingStatus;
use App\Domains\Merchants\Enums\RiskRating;
use App\Support\Security\BlindIndex;
use App\Support\Security\Mask;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A merchant — the individual or principal account holder.
 *
 * Identity numbers deserve particular care. `bvn` and `nin` are encrypted at
 * rest and are never returned by an API resource in plaintext unless the caller
 * holds merchants.view_sensitive. Uniqueness and lookup run against the blind
 * index columns, not the ciphertext, because Laravel's encryption is
 * randomised and the same BVN encrypts differently every time.
 *
 * @property int $id
 * @property string $merchant_number
 * @property string $first_name
 * @property string $last_name
 * @property string|null $bvn
 * @property string|null $nin
 * @property OnboardingStatus $onboarding_status
 * @property MerchantStatus $merchant_status
 * @property KycStatus $kyc_status
 * @property RiskRating|null $risk_rating
 */
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use BelongsToBranch, HasFactory, SoftDeletes;

    public const BVN_INDEX_DOMAIN = 'merchant.bvn';

    public const NIN_INDEX_DOMAIN = 'merchant.nin';

    protected $table = 'merchants';

    /**
     * Identity numbers are absent by design. They are assigned explicitly by
     * the merchant service, which also maintains their blind indexes — a mass
     * assignment would set the ciphertext and leave the index stale, silently
     * breaking duplicate detection.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'gender',
        'marital_status',
        'employment_status',
        'preferred_language',
        'phone',
        'alternative_phone',
        'email',
        'residential_address',
        'city',
        'state',
        'country',
        'branch_id',
        'assigned_officer_id',
    ];

    /**
     * Never serialised. A resource that forgets to exclude them cannot leak
     * them by accident.
     *
     * @var list<string>
     */
    protected $hidden = [
        'bvn',
        'nin',
        'bvn_index',
        'nin_index',
    ];

    // --- Relationships -----------------------------------------------------

    /**
     * @return HasMany<Business, $this>
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * The merchant's internal account. Opened at approval, so a merchant in
     * draft legitimately has none.
     *
     * @return HasOne<MerchantAccount, $this>
     */
    public function account(): HasOne
    {
        return $this->hasOne(MerchantAccount::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function assignedOfficer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_officer_id');
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
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }

    // --- Identity ----------------------------------------------------------

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }

    /**
     * The BVN as most staff see it: 22*******14.
     */
    public function maskedBvn(): ?string
    {
        return Mask::identityNumber($this->bvn);
    }

    public function maskedNin(): ?string
    {
        return Mask::identityNumber($this->nin);
    }

    // --- Lifecycle ---------------------------------------------------------

    public function isApproved(): bool
    {
        return $this->onboarding_status->isApproved();
    }

    /**
     * Whether a new loan may be originated for this merchant.
     *
     * Requires the merchant to be approved, transacting, KYC-verified, and to
     * have at least one active business — the loan is advanced against trading
     * activity, so there has to be some.
     */
    public function canBorrow(): bool
    {
        return $this->onboarding_status->isOperational()
            && $this->merchant_status->canBorrow()
            && $this->kyc_status->isVerified();
    }

    /**
     * @param  Builder<Merchant>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('onboarding_status', OnboardingStatus::Approved->value);
    }

    /**
     * @param  Builder<Merchant>  $query
     */
    public function scopeWithBvnIndex(Builder $query, string $bvn): void
    {
        // Matched on the keyed hash: the ciphertext differs on every write and
        // cannot be compared directly.
        $query->where('bvn_index', BlindIndex::hash($bvn, self::BVN_INDEX_DOMAIN));
    }

    /**
     * @param  Builder<Merchant>  $query
     */
    public function scopeWithNinIndex(Builder $query, string $nin): void
    {
        $query->where('nin_index', BlindIndex::hash($nin, self::NIN_INDEX_DOMAIN));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'onboarding_status' => OnboardingStatus::class,
            'merchant_status' => MerchantStatus::class,
            'kyc_status' => KycStatus::class,
            'risk_rating' => RiskRating::class,
            // Encrypted at rest. A database dump yields no identity numbers.
            'bvn' => 'encrypted',
            'nin' => 'encrypted',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
