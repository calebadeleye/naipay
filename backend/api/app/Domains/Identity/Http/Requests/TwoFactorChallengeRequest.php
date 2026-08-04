<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TwoFactorChallengeRequest extends FormRequest
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
            'challenge_token' => ['required', 'string', 'size:64'],
            // Accepts a six-digit TOTP code or a recovery code, so the format
            // is not constrained further here.
            'code' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Enter the code from your authenticator app, or a recovery code.',
        ];
    }
}
