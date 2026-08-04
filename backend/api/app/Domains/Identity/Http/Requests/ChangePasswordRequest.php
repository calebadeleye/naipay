<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // Password::defaults() is set in AppServiceProvider: at least 12
            // characters with mixed case, a number and a symbol, and checked
            // against known breach corpora in production.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
