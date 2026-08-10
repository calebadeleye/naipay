<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotMerchantPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
        ];
    }
}
