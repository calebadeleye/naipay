<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Requests;

use App\Domains\Businesses\Enums\CategoryStatus;
use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBusinessCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::CategoriesManage->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Uniqueness within the level is guaranteed by the database; this
            // rule exists so a duplicate reads as a field error rather than a
            // constraint violation.
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('business_categories', 'name')
                    ->where('parent_id', $this->input('parent_id')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('business_categories', 'id')],
            'icon' => ['nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::enum(CategoryStatus::class)],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
