<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class ReverseRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::RepaymentsReverse->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
