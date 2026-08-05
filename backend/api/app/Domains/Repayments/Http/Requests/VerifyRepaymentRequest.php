<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class VerifyRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::RepaymentsVerify->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
