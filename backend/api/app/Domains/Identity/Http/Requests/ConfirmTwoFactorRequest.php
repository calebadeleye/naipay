<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmTwoFactorRequest extends FormRequest
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
            // Enrolment is confirmed with a TOTP code only. Recovery codes do
            // not exist until enrolment completes.
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.digits' => 'Enter the six-digit code shown in your authenticator app.',
        ];
    }
}
