<?php

declare(strict_types=1);

namespace App\Domains\Branches\Http\Requests;

use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeBranchStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(BranchStatus::class)],
            // Suspending or closing a branch withdraws it from use; the audit
            // trail is only useful if the reason recorded is a real one.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Give a reason of at least 10 characters. This is recorded in the audit log.',
        ];
    }
}
