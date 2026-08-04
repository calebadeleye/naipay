<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Resources;

use App\Domains\Businesses\Models\BusinessCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessCategory
 */
final class BusinessCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BusinessCategory $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'name' => $category->name,
            'qualified_name' => $category->qualifiedName(),
            'slug' => $category->slug,
            'description' => $category->description,
            'icon' => $category->icon,

            'parent_id' => $category->parent_id,
            'is_root' => $category->isRoot(),

            'status' => $category->status->value,
            'status_label' => $category->status->label(),
            'is_selectable' => $category->isSelectable(),

            'display_order' => $category->display_order,

            'children' => BusinessCategoryResource::collection($this->whenLoaded('children')),
            'children_count' => $this->whenCounted('children'),

            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }
}
