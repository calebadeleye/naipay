<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBusinessCategoryRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('business_categories', 'id')],
            'icon' => ['nullable', 'string', 'max:60'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
