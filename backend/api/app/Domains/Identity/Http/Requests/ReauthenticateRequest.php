<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReauthenticateRequest extends FormRequest
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
            'password' => ['required', 'string'],
            // Required only when the account has two-factor enabled — the
            // service itself decides that, since it depends on the account,
            // not the request.
            'code' => ['nullable', 'string'],
        ];
    }
}
