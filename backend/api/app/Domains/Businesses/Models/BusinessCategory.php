<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Models;

use App\Domains\Businesses\Enums\CategoryStatus;
use Database\Factories\BusinessCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business category or subcategory.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $parent_id
 * @property CategoryStatus $status
 * @property int $display_order
 */
class BusinessCategory extends Model
{
    /** @use HasFactory<BusinessCategoryFactory> */
    use HasFactory;

    protected $table = 'business_categories';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        'icon',
        'status',
        'display_order',
        'created_by',
    ];

    /**
     * @return BelongsTo<BusinessCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<BusinessCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order')->orderBy('name');
    }

    /**
     * Top-level categories only.
     *
     * @param  Builder<BusinessCategory>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<BusinessCategory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CategoryStatus::Active->value);
    }

    /**
     * @param  Builder<BusinessCategory>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('display_order')->orderBy('name');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isSelectable(): bool
    {
        return $this->status->isSelectable();
    }

    /**
     * "Agriculture and Farming › Livestock and Poultry", for display on a
     * business record where the parent alone is ambiguous.
     */
    public function qualifiedName(): string
    {
        return $this->parent === null
            ? $this->name
            : "{$this->parent->name} › {$this->name}";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CategoryStatus::class,
            'display_order' => 'integer',
        ];
    }
}
