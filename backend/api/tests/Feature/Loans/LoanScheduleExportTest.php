<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Loans\Notifications\LoanScheduleNotification;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Downloading and emailing a loan's repayment schedule as a PDF.
 */
final class LoanScheduleExportTest extends TestCase
{
    use RefreshDatabase;

    private function loanWithSchedule(): Loan
    {
        $loan = Loan::factory()->disbursed()->create();

        LoanScheduleEntry::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->addDay()->toDateString(),
            'principal_due' => '5800.00',
            'interest_due' => '200.00',
            'fee_due' => '0.00',
        ]);

        return $loan->fresh();
    }

    #[Test]
    public function downloading_the_schedule_returns_a_pdf(): void
    {
        $this->actingAsStaffWith([Permission::LoansView]);
        $loan = $this->loanWithSchedule();

        $response = $this->get("/api/v1/admin/loans/{$loan->id}/schedule/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotEmpty($response->getContent());
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function emailing_the_schedule_sends_it_to_the_merchants_address_on_file(): void
    {
        Notification::fake();

        $this->actingAsStaffWith([Permission::LoansView]);
        $loan = $this->loanWithSchedule();

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/schedule/email");

        $response->assertOk();

        $merchant = Merchant::query()->findOrFail($loan->merchant_id);

        Notification::assertSentTo($merchant, LoanScheduleNotification::class);
    }

    #[Test]
    public function emailing_the_schedule_is_refused_when_the_merchant_has_no_email(): void
    {
        Notification::fake();

        $this->actingAsStaffWith([Permission::LoansView]);
        $loan = $this->loanWithSchedule();

        Merchant::query()->where('id', $loan->merchant_id)->update(['email' => null]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/schedule/email");

        $response->assertStatus(422);

        Notification::assertNothingSent();
    }
}
