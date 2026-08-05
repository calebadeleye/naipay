<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reason-required workflow action: reject, return to draft, or withdraw.
 *
 * Authorization is enforced by the route's `permission:` middleware, not
 * here — each action carries a different permission, so a single request
 * class cannot check just one.
 */
final class LoanApplicationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
