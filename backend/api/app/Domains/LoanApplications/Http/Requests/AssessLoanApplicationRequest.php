<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class AssessLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::LoanApplicationsAssess->value) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
