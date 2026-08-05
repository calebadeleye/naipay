<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Businesses\Enums\VerificationStatus;
use App\Domains\Businesses\Models\Business;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Merchants\Enums\KycStatus;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The audit log is written by every other domain already (AuditLogger);
 * this is only the read surface over it, and it is immutable by
 * construction — AuditLog itself refuses an update or a delete — so there
 * is nothing to test here beyond visibility and filtering.
 */
final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function viewing_the_audit_log_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    #[Test]
    public function viewing_the_audit_log_requires_the_audit_view_permission(): void
    {
        $this->actingAsStaffWith([]);

        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }

    #[Test]
    public function entries_can_be_filtered_by_module(): void
    {
        $this->actingAsStaffWith([Permission::AuditView]);

        AuditLog::query()->create(['action' => 'loan.disbursed', 'module' => 'loans', 'event_type' => 'update']);
        AuditLog::query()->create(['action' => 'repayment.approved', 'module' => 'repayments', 'event_type' => 'update']);

        $response = $this->getJson('/api/v1/admin/audit-logs?module=loans');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('loan.disbursed', $response->json('data.0.action'));
    }

    #[Test]
    public function a_single_entry_can_be_retrieved_with_its_changes(): void
    {
        $this->actingAsStaffWith([Permission::AuditView]);

        $log = AuditLog::query()->create([
            'action' => 'loan.approved',
            'module' => 'loans',
            'event_type' => 'update',
            'old_values' => ['status' => 'pending_approval'],
            'new_values' => ['status' => 'pending_disbursement'],
        ]);

        $response = $this->getJson("/api/v1/admin/audit-logs/{$log->id}");

        $response->assertOk();
        $this->assertSame('pending_approval', $response->json('data.changes.status.old'));
        $this->assertSame('pending_disbursement', $response->json('data.changes.status.new'));
    }

    #[Test]
    public function an_audit_log_entry_cannot_be_modified_or_deleted(): void
    {
        $log = AuditLog::query()->create(['action' => 'test.action', 'module' => 'test', 'event_type' => 'update']);

        $this->expectException(RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }

    // --- Compliance overview -------------------------------------------------------

    #[Test]
    public function the_compliance_overview_lists_merchants_awaiting_kyc(): void
    {
        $this->actingAsStaffWith([Permission::AuditView]);

        Merchant::factory()->create([
            'kyc_status' => KycStatus::Pending,
            'submitted_at' => now()->startOfDay()->subDays(10),
        ]);
        Merchant::factory()->approved()->create();

        $response = $this->getJson('/api/v1/admin/reports/compliance-overview');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.pending_kyc.count'));
        $this->assertSame(10, $response->json('data.pending_kyc.merchants.0.days_waiting'));
    }

    #[Test]
    public function the_compliance_overview_lists_businesses_awaiting_verification(): void
    {
        $this->actingAsStaffWith([Permission::AuditView]);

        Business::factory()->create([
            'verification_status' => VerificationStatus::Pending,
        ]);
        Business::factory()->verified()->create();

        $response = $this->getJson('/api/v1/admin/reports/compliance-overview');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.pending_business_verification.count'));
    }
}
