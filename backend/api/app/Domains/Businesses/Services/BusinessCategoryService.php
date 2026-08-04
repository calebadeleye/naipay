<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Businesses\Enums\CategoryStatus;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Identity\Models\Staff;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maintains the business category vocabulary.
 *
 * There is no delete: categories are deactivated so that businesses already
 * filed under them keep their classification and historical category reports
 * stay comparable.
 */
final class BusinessCategoryService
{
    private const MODULE = 'business_categories';

    /** Two levels only — a category and its subcategories. */
    private const MAX_DEPTH = 2;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Staff $actor): BusinessCategory
    {
        return DB::transaction(function () use ($attributes, $actor): BusinessCategory {
            $parentId = $attributes['parent_id'] ?? null;

            $this->assertParentIsUsable($parentId);

            $category = new BusinessCategory($attributes);
            $category->slug = $this->makeSlug((string) $attributes['name'], $parentId);
            $category->created_by = $actor->getKey();
            $category->status = CategoryStatus::from($attributes['status'] ?? CategoryStatus::Active->value);
            $category->display_order = (int) ($attributes['display_order'] ?? $this->nextOrderWithin($parentId));

            $category->save();

            $this->audit->recordCreation('category.created', self::MODULE, $category, $actor);

            return $category->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(BusinessCategory $category, array $attributes, Staff $actor): BusinessCategory
    {
        return DB::transaction(function () use ($category, $attributes, $actor): BusinessCategory {
            $before = $category->getAttributes();

            if (array_key_exists('parent_id', $attributes)) {
                $this->assertReparentIsSafe($category, $attributes['parent_id']);
            }

            $category->fill($attributes);

            // The slug is derived, never edited directly: it identifies the
            // category to the seeder, and letting it drift would make a reseed
            // create a duplicate.
            if ($category->isDirty('name') || $category->isDirty('parent_id')) {
                $category->slug = $this->makeSlug($category->name, $category->parent_id, $category->id);
            }

            $category->save();

            $this->audit->recordChange('category.updated', self::MODULE, $category, $before, actor: $actor);

            return $category->fresh();
        });
    }

    /**
     * Activates or deactivates a category.
     *
     * Deactivating a parent deactivates its subcategories too — leaving a
     * selectable child under a withdrawn parent would put businesses back into
     * the branch of the taxonomy that was just closed.
     */
    public function changeStatus(
        BusinessCategory $category,
        CategoryStatus $status,
        Staff $actor,
    ): BusinessCategory {
        if ($category->status === $status) {
            throw new DomainException("This category is already {$status->label()}.");
        }

        if ($status === CategoryStatus::Active && $category->parent !== null && ! $category->parent->isSelectable()) {
            throw new DomainException(
                'Activate the parent category first. A subcategory cannot be selectable while its parent is not.',
            );
        }

        return DB::transaction(function () use ($category, $status, $actor): BusinessCategory {
            $before = $category->getAttributes();

            $category->status = $status;
            $category->save();

            if ($status === CategoryStatus::Inactive && $category->isRoot()) {
                $category->children()->update(['status' => CategoryStatus::Inactive->value]);
            }

            $this->audit->recordChange(
                "category.{$status->value}",
                self::MODULE,
                $category,
                $before,
                actor: $actor,
            );

            return $category->fresh();
        });
    }

    /**
     * Reorders categories within one level.
     *
     * @param  array<int, int>  $orderedIds  Category ids in their intended order.
     */
    public function reorder(array $orderedIds, ?int $parentId, Staff $actor): void
    {
        DB::transaction(function () use ($orderedIds, $parentId, $actor): void {
            $categories = BusinessCategory::query()
                ->whereIn('id', $orderedIds)
                ->get()
                ->keyBy('id');

            foreach ($orderedIds as $index => $id) {
                $category = $categories->get($id);

                if ($category === null) {
                    throw new DomainException("Category [{$id}] was not found.");
                }

                // Reordering must not silently move a category to another
                // level; that is a re-parent, which goes through update().
                if ($category->parent_id !== $parentId) {
                    throw new DomainException(
                        "Category [{$category->name}] does not belong to the level being reordered.",
                    );
                }

                $category->forceFill(['display_order' => ($index + 1) * 10])->save();
            }

            $this->audit->record(
                action: 'category.reordered',
                module: self::MODULE,
                newValues: ['parent_id' => $parentId, 'order' => $orderedIds],
                eventType: 'update',
                actor: $actor,
            );
        });
    }

    /**
     * The picker tree: active parents, each with its active subcategories.
     *
     * @return array<int, array<string, mixed>>
     */
    public function selectableTree(): array
    {
        return BusinessCategory::query()
            ->roots()
            ->active()
            ->ordered()
            ->with(['children' => fn ($query) => $query->active()->ordered()])
            ->get()
            ->map(fn (BusinessCategory $category): array => [
                'value' => $category->id,
                'label' => $category->name,
                'icon' => $category->icon,
                // The parent name is already in hand, so the qualified label is
                // composed here rather than via $child->qualifiedName(), which
                // would lazy-load the parent once per subcategory.
                'children' => $category->children->map(fn (BusinessCategory $child): array => [
                    'value' => $child->id,
                    'label' => $child->name,
                    'qualified_label' => "{$category->name} › {$child->name}",
                ])->all(),
            ])
            ->all();
    }

    private function assertParentIsUsable(mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = BusinessCategory::find($parentId);

        if ($parent === null) {
            throw new DomainException(
                'The selected parent category was not found.',
                ['parent_id' => ['The selected parent category does not exist.']],
            );
        }

        if (! $parent->isRoot()) {
            throw new DomainException(
                'Categories nest one level deep only.',
                ['parent_id' => ['A subcategory cannot itself have subcategories.']],
            );
        }
    }

    private function assertReparentIsSafe(BusinessCategory $category, mixed $newParentId): void
    {
        if ($newParentId === null) {
            return;
        }

        if ((int) $newParentId === (int) $category->id) {
            throw new DomainException(
                'A category cannot be its own parent.',
                ['parent_id' => ['Choose a different parent category.']],
            );
        }

        // Moving a parent under another parent would orphan its children past
        // the permitted depth.
        if ($category->children()->exists()) {
            throw new DomainException(
                'This category has subcategories and cannot itself become a subcategory.',
                ['parent_id' => ['Move or remove its subcategories first.']],
            );
        }

        $this->assertParentIsUsable($newParentId);
    }

    private function makeSlug(string $name, ?int $parentId, ?int $ignoreId = null): string
    {
        $base = $parentId === null ? Str::slug($name) : Str::slug($name).'-'.$parentId;

        $slug = $base;
        $suffix = 1;

        while (
            BusinessCategory::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function nextOrderWithin(?int $parentId): int
    {
        $highest = BusinessCategory::query()
            ->where('parent_id', $parentId)
            ->max('display_order');

        return (int) $highest + 10;
    }
}
