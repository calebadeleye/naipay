<?php

declare(strict_types=1);

namespace App\Domains\Branches\Http\Requests;

use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::BranchesManage->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Optional: allocated from the branch sequence when omitted, which
            // is the normal path.
            'branch_code' => ['sometimes', 'string', 'max:20', Rule::unique('branches', 'branch_code')],

            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],

            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],

            'manager_id' => ['nullable', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],

            'status' => ['sometimes', Rule::enum(BranchStatus::class)],
            'opened_at' => ['nullable', 'date'],
        ];
    }
}
