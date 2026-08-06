<?php

declare(strict_types=1);

namespace App\Domains\Investors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InvestorLoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter your email address.',
            'password.required' => 'Enter your password.',
        ];
    }
}
