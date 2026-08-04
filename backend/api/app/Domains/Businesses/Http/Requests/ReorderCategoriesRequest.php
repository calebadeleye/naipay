<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReorderCategoriesRequest extends FormRequest
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
            // The level being reordered. Null reorders the top level.
            'parent_id' => ['present', 'nullable', 'integer', Rule::exists('business_categories', 'id')],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', Rule::exists('business_categories', 'id')],
        ];
    }
}
