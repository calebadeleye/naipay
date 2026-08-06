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
            // Required only when the account has two-factor enabled and the
            // operation does not waive it — the service itself decides that,
            // since it depends on the account and operation, not the request.
            'code' => ['nullable', 'string'],
            // Which naipay.security.reauthentication_required_operations
            // entry this is for, so the service can tell whether a
            // password-only reauthentication is acceptable. Not trusted for
            // anything beyond that: the operation actually being protected is
            // re-checked independently by MakerCheckerGuard when it is
            // attempted.
            'operation' => ['nullable', 'string'],
        ];
    }
}
