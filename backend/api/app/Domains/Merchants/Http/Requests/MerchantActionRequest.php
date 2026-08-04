<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared shape for merchant workflow actions that require a recorded reason —
 * rejection, suspension, reinstatement, return to draft.
 *
 * Authorisation is on the route: each action carries a different permission,
 * and folding them into one request class would blur that.
 */
final class MerchantActionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Give a reason of at least 10 characters. This is recorded in the audit log.',
        ];
    }
}
