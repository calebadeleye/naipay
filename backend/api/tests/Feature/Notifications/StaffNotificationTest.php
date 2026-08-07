<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domains\Approvals\Notifications\ApprovalDecisionNotification;
use App\Domains\Approvals\Services\ApprovalNotifier;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Database\Seeders\ChartOfAccountsSeeder;
use App\Domains\Loans\Approvals\LoanApproval;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Loans\Services\LoanService;
use App\Domains\Notifications\Models\StaffNotification;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Domains\Repayments\Services\RepaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Staff-facing notifications: the bell in the admin console's topbar, and
 * the mail that accompanies it whenever a maker-checked record is decided.
 *
 * Separate from `NotificationTest`, which covers the merchant-facing
 * notifications this codebase already had — this file only covers the new
 * staff inbox and the `ApprovalNotifier` wiring that feeds it.
 */
final class StaffNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
    }

    // --- Wiring: a decision creates a notification for the maker -----------------

    #[Test]
    public function approving_a_repayment_creates_an_in_app_notification_and_emails_the_verifier(): void
    {
        $loan = Loan::factory()->disbursed()->create();
        LoanScheduleEntry::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->toDateString(),
            'principal_due' => '5000.00',
            'interest_due' => '1000.00',
            'fee_due' => '0.00',
        ]);

        $verifier = Staff::factory()->create();
        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '6000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $approver = $this->actingAsRole(Role::FinanceManager);
        app(RepaymentService::class)->approve($repayment->fresh(), $approver);

        $notification = StaffNotification::query()->where('staff_id', $verifier->id)->first();

        $this->assertNotNull($notification);
        $this->assertSame('repayment.approve', $notification->type);
        $this->assertStringContainsString($repayment->repayment_reference, $notification->title);
        $this->assertSame(Repayment::class, $notification->subject_type);
        $this->assertSame($repayment->id, $notification->subject_id);
        $this->assertFalse($notification->isRead());
    }

    #[Test]
    public function rejecting_a_repayment_notifies_the_verifier_with_the_reason(): void
    {
        $loan = Loan::factory()->disbursed()->create();
        $verifier = Staff::factory()->create();
        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '6000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $actor = $this->actingAsRole(Role::FinanceManager);
        app(RepaymentService::class)->reject($repayment, 'Bank reference does not match any statement line.', $actor);

        $notification = StaffNotification::query()->where('staff_id', $verifier->id)->first();

        $this->assertNotNull($notification);
        $this->assertSame('Bank reference does not match any statement line.', $notification->body);
    }

    #[Test]
    public function approving_a_loan_notifies_whoever_created_it(): void
    {
        Notification::fake();

        $creator = Staff::factory()->create();
        $loan = Loan::factory()->create(['created_by' => $creator->id]);

        $approver = $this->actingAsRole(Role::CreditManager);
        app(LoanService::class)->approve($loan, $approver);

        Notification::assertSentTo(
            $creator,
            ApprovalDecisionNotification::class,
        );
    }

    #[Test]
    public function the_acting_staff_member_is_never_notified_of_their_own_decision(): void
    {
        $staff = Staff::factory()->create();
        $loan = Loan::factory()->create(['created_by' => $staff->id]);

        app(ApprovalNotifier::class)->notifyDecision(
            new LoanApproval($loan),
            'approved',
            $loan->loan_reference,
            "/loans/{$loan->id}",
            $staff,
            subject: $loan,
        );

        $this->assertSame(0, StaffNotification::query()->where('staff_id', $staff->id)->count());
    }

    // --- Inbox endpoints -----------------------------------------------------------

    #[Test]
    public function a_staff_member_only_sees_their_own_notifications(): void
    {
        $staff = $this->actingAsStaffWith([]);
        $other = Staff::factory()->create();

        StaffNotification::create([
            'staff_id' => $staff->id,
            'type' => 'repayment.approve',
            'title' => 'Mine',
            'action_url' => '/repayments/1',
        ]);
        StaffNotification::create([
            'staff_id' => $other->id,
            'type' => 'repayment.approve',
            'title' => 'Not mine',
            'action_url' => '/repayments/2',
        ]);

        $response = $this->getJson('/api/v1/admin/notifications');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mine', $response->json('data.0.title'));
        $this->assertSame(1, $response->json('meta.unread_count'));
    }

    #[Test]
    public function marking_a_notification_read_updates_it(): void
    {
        $staff = $this->actingAsStaffWith([]);

        $notification = StaffNotification::create([
            'staff_id' => $staff->id,
            'type' => 'repayment.approve',
            'title' => 'A decision',
            'action_url' => '/repayments/1',
        ]);

        $response = $this->postJson("/api/v1/admin/notifications/{$notification->id}/read");

        $response->assertOk();
        $this->assertTrue($response->json('data.read'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    #[Test]
    public function a_staff_member_cannot_mark_someone_elses_notification_read(): void
    {
        $owner = Staff::factory()->create();
        $notification = StaffNotification::create([
            'staff_id' => $owner->id,
            'type' => 'repayment.approve',
            'title' => 'Not yours',
            'action_url' => '/repayments/1',
        ]);

        $this->actingAsStaffWith([]);

        $response = $this->postJson("/api/v1/admin/notifications/{$notification->id}/read");

        $response->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    #[Test]
    public function mark_all_read_clears_only_the_callers_own_unread_notifications(): void
    {
        $staff = $this->actingAsStaffWith([]);
        $other = Staff::factory()->create();

        $mine = StaffNotification::create([
            'staff_id' => $staff->id,
            'type' => 'repayment.approve',
            'title' => 'Mine',
            'action_url' => '/repayments/1',
        ]);
        $theirs = StaffNotification::create([
            'staff_id' => $other->id,
            'type' => 'repayment.approve',
            'title' => 'Theirs',
            'action_url' => '/repayments/2',
        ]);

        $response = $this->postJson('/api/v1/admin/notifications/read-all');

        $response->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }
}
