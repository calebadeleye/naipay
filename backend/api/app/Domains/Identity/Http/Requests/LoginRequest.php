<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
            // Either an email address or a username; the service resolves
            // which. Deliberately not validated as an email.
            'identifier' => ['required', 'string', 'max:190'],
            // No complexity rules on sign-in: the policy applies when a
            // password is set, and applying it here would reject legitimate
            // holders of older passwords.
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier.required' => 'Enter your email address or username.',
            'password.required' => 'Enter your password.',
        ];
    }
}
