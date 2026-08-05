<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanScheduleEntry>
 */
final class LoanScheduleEntryFactory extends Factory
{
    protected $model = LoanScheduleEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory()->disbursed(),
            'installment_number' => 1,
            'due_date' => now()->addDay()->toDateString(),
            'opening_principal' => '150000.00',
            'principal_due' => '7500.00',
            'interest_due' => '1500.00',
            'fee_due' => '0.00',
        ];
    }
}
