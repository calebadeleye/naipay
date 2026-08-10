<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WithdrawMerchantLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
