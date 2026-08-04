<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
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
        // Deliberately not validated against the staff table. An `exists` rule
        // here would turn the endpoint into an account-enumeration oracle,
        // which is exactly what the service's silent return avoids.
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
        ];
    }
}
