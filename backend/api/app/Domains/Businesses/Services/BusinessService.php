<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Businesses\Enums\BusinessStatus;
use App\Domains\Businesses\Enums\BusinessType;
use App\Domains\Businesses\Enums\VerificationStatus;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Exceptions\DomainException;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Businesses belonging to a merchant.
 */
final class BusinessService
{
    private const MODULE = 'businesses';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Merchant $merchant, array $attributes, Staff $actor): Business
    {
        return DB::transaction(function () use ($merchant, $attributes, $actor): Business {
            $this->assertCategoriesAreSelectable(
                $attributes['business_category_id'] ?? null,
                $attributes['business_subcategory_id'] ?? null,
            );

            $business = new Business($attributes);

            $business->merchant_id = $merchant->getKey();
            $business->business_number = $this->references->next('business');
            $business->status = BusinessStatus::Inactive;
            $business->verification_status = VerificationStatus::Unverified;
            $business->created_by = $actor->getKey();

            $business->save();

            $this->audit->recordCreation('business.created', self::MODULE, $business, $actor);

            return $business->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Business $business, array $attributes, Staff $actor): Business
    {
        return DB::transaction(function () use ($business, $attributes, $actor): Business {
            $before = $business->getAttributes();

            if (array_key_exists('business_category_id', $attributes) || array_key_exists('business_subcategory_id', $attributes)) {
                $this->assertCategoriesAreSelectable(
                    $attributes['business_category_id'] ?? $business->business_category_id,
                    $attributes['business_subcategory_id'] ?? $business->business_subcategory_id,
                );
            }

            $business->fill($attributes);
            $business->save();

            $this->audit->recordChange('business.updated', self::MODULE, $business, $before, actor: $actor);

            return $business->fresh();
        });
    }

    /**
     * Records the outcome of a field verification.
     */
    public function verify(Business $business, Staff $actor): Business
    {
        if ($business->verification_status->isVerified()) {
            throw new DomainException('This business has already been verified.');
        }

        return DB::transaction(function () use ($business, $actor): Business {
            $before = $business->getAttributes();

            $business->forceFill([
                'verification_status' => VerificationStatus::Verified,
                // Verification is what makes a business operational; there is
                // no separate activation step to forget.
                'status' => BusinessStatus::Active,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit->recordChange('business.verified', self::MODULE, $business, $before, actor: $actor);

            return $business->fresh();
        });
    }

    public function rejectVerification(Business $business, string $reason, Staff $actor): Business
    {
        return DB::transaction(function () use ($business, $reason, $actor): Business {
            $before = $business->getAttributes();

            $business->forceFill([
                'verification_status' => VerificationStatus::Rejected,
                'status' => BusinessStatus::Inactive,
            ])->save();

            $this->audit->recordChange(
                'business.verification_rejected',
                self::MODULE,
                $business,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $business->fresh();
        });
    }

    public function changeStatus(
        Business $business,
        BusinessStatus $status,
        string $reason,
        Staff $actor,
    ): Business {
        if ($business->status === $status) {
            throw new DomainException("This business is already {$status->label()}.");
        }

        if ($status === BusinessStatus::Active && ! $business->verification_status->isVerified()) {
            throw new DomainException(
                'A business must be verified before it can be made active.',
            );
        }

        return DB::transaction(function () use ($business, $status, $reason, $actor): Business {
            $before = $business->getAttributes();

            $business->forceFill(['status' => $status])->save();

            $this->audit->recordChange(
                "business.{$status->value}",
                self::MODULE,
                $business,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $business->fresh();
        });
    }

    /**
     * @return array<int, array{value: string, label: string, requires_cac: bool}>
     */
    public function businessTypeOptions(): array
    {
        return BusinessType::options();
    }

    /**
     * Categories must come from the controlled vocabulary, must be selectable,
     * and a subcategory must actually belong to the chosen parent.
     *
     * Without the last check a business could be filed under
     * "Agriculture › Hairdressing", which no report would ever make sense of.
     */
    private function assertCategoriesAreSelectable(mixed $categoryId, mixed $subcategoryId): void
    {
        $category = BusinessCategory::find($categoryId);

        if ($category === null) {
            throw new DomainException(
                'The selected business category was not found.',
                ['business_category_id' => ['Choose a business category from the list.']],
            );
        }

        if (! $category->isSelectable()) {
            throw new DomainException(
                "The category “{$category->name}” is no longer available for new businesses.",
                ['business_category_id' => ['This category has been withdrawn.']],
            );
        }

        if (! $category->isRoot()) {
            throw new DomainException(
                'Choose a top-level category, then its subcategory.',
                ['business_category_id' => ['This is a subcategory, not a category.']],
            );
        }

        if ($subcategoryId === null) {
            return;
        }

        $subcategory = BusinessCategory::find($subcategoryId);

        if ($subcategory === null || $subcategory->parent_id !== $category->id) {
            throw new DomainException(
                'The selected subcategory does not belong to that category.',
                ['business_subcategory_id' => ['Choose a subcategory of the selected category.']],
            );
        }

        if (! $subcategory->isSelectable()) {
            throw new DomainException(
                "The subcategory “{$subcategory->name}” is no longer available for new businesses.",
                ['business_subcategory_id' => ['This subcategory has been withdrawn.']],
            );
        }
    }
}
