<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Security\BlindIndex;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How BVN and NIN are stored, searched and shown.
 *
 * These are the most sensitive fields in the system. A BVN is 11 digits — the
 * entire space is 10^11, small enough that a plain hash would be reversed by
 * brute force in minutes — so the storage scheme matters more than usual.
 */
final class MerchantIdentityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function identity_numbers_are_encrypted_at_rest(): void
    {
        $merchant = Merchant::factory()->withBvn('22123456714')->withNin('84123456721')->create();

        $raw = DB::table('merchants')->where('id', $merchant->id)->first();

        // A database dump must yield no identity numbers.
        $this->assertNotSame('22123456714', $raw->bvn);
        $this->assertNotSame('84123456721', $raw->nin);
        $this->assertStringNotContainsString('22123456714', (string) $raw->bvn);

        // But the application still reads them.
        $this->assertSame('22123456714', $merchant->fresh()->bvn);
        $this->assertSame('84123456721', $merchant->fresh()->nin);
    }

    #[Test]
    public function the_same_number_encrypts_differently_each_time(): void
    {
        $first = Merchant::factory()->create();
        $second = Merchant::factory()->create();

        $first->forceFill(['bvn' => '22123456714'])->save();
        $second->forceFill(['bvn' => '22123456714'])->save();

        $firstCipher = DB::table('merchants')->where('id', $first->id)->value('bvn');
        $secondCipher = DB::table('merchants')->where('id', $second->id)->value('bvn');

        // Randomised encryption is why uniqueness cannot be enforced on the
        // ciphertext, and why the blind index exists at all.
        $this->assertNotSame($firstCipher, $secondCipher);
    }

    #[Test]
    public function the_blind_index_is_deterministic_and_keyed(): void
    {
        $first = BlindIndex::hash('22123456714', Merchant::BVN_INDEX_DOMAIN);
        $second = BlindIndex::hash('22123456714', Merchant::BVN_INDEX_DOMAIN);

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));

        // The plain SHA-256 of the same value must not appear — otherwise an
        // attacker with the database could rebuild the 10^11 space offline.
        $this->assertNotSame(hash('sha256', '22123456714'), $first);
    }

    #[Test]
    public function indexing_is_namespaced_per_field(): void
    {
        // The same 11 digits used as a BVN and as a NIN must not produce the
        // same index, or one would leak the other's presence.
        $this->assertNotSame(
            BlindIndex::hash('22123456714', Merchant::BVN_INDEX_DOMAIN),
            BlindIndex::hash('22123456714', Merchant::NIN_INDEX_DOMAIN),
        );
    }

    #[Test]
    public function formatting_does_not_change_the_index(): void
    {
        $this->assertSame(
            BlindIndex::hash('22123456714', Merchant::BVN_INDEX_DOMAIN),
            BlindIndex::hash(' 22 123 456 714 ', Merchant::BVN_INDEX_DOMAIN),
        );
    }

    #[Test]
    public function a_merchant_can_be_found_by_identity_number(): void
    {
        Merchant::factory()->withBvn('22123456714')->create(['last_name' => 'Okafor']);
        Merchant::factory()->withBvn('33987654321')->create(['last_name' => 'Adeyemi']);

        $found = Merchant::query()->withBvnIndex('22123456714')->first();

        $this->assertNotNull($found);
        $this->assertSame('Okafor', $found->last_name);
    }

    #[Test]
    public function the_same_bvn_cannot_be_registered_twice(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        $this->postJson('/api/v1/admin/merchants', [
            'first_name' => 'Grace', 'last_name' => 'Okafor',
            'phone' => '08031234567', 'bvn' => '22123456714',
        ])->assertCreated();

        $this->postJson('/api/v1/admin/merchants', [
            'first_name' => 'Someone', 'last_name' => 'Else',
            'phone' => '08031234568', 'bvn' => '22123456714',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['bvn']]);

        $this->assertSame(1, Merchant::query()->count());
    }

    #[Test]
    public function the_database_refuses_a_duplicate_index_even_without_the_service(): void
    {
        Merchant::factory()->withBvn('22123456714')->create();

        // The service check gives a friendly field error; this unique key is
        // what holds if a future code path forgets to call it.
        $this->expectException(QueryException::class);

        Merchant::factory()->withBvn('22123456714')->create();
    }

    // --- Masking -----------------------------------------------------------

    #[Test]
    public function identity_numbers_are_masked_for_staff_without_the_permission(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->withBvn('22123456714')->withNin('84123456721')->create();

        $response = $this->getJson("/api/v1/admin/merchants/{$merchant->id}")->assertOk();

        $this->assertSame('22*******14', $response->json('data.identity.bvn_masked'));
        $this->assertSame('84*******21', $response->json('data.identity.nin_masked'));

        // The full value is absent from the payload entirely — not hidden by
        // the client, which would leave it in the response body and any cache
        // in between.
        $this->assertNull($response->json('data.identity.bvn'));
        $this->assertFalse($response->json('data.identity.unmasked'));
        $this->assertStringNotContainsString('22123456714', (string) $response->getContent());
    }

    #[Test]
    public function a_compliance_officer_sees_the_full_number(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->withBvn('22123456714')->create();

        $response = $this->getJson("/api/v1/admin/merchants/{$merchant->id}")->assertOk();

        // Verifying a BVN against a source document is the job.
        $this->assertSame('22123456714', $response->json('data.identity.bvn'));
        $this->assertTrue($response->json('data.identity.unmasked'));
    }

    #[Test]
    public function a_list_response_never_carries_unmasked_numbers(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        Merchant::factory()->count(3)->withBvn()->create();
        Merchant::factory()->withBvn('55123456799')->create();

        $body = (string) $this->getJson('/api/v1/admin/merchants')->assertOk()->getContent();

        // Even for a permitted role: one screen of results would otherwise put
        // fifty BVNs into a single payload.
        $this->assertStringNotContainsString('55123456799', $body);
        $this->assertStringContainsString('55*******99', $body);
    }

    #[Test]
    public function identity_numbers_cannot_be_searched(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        Merchant::factory()->withBvn('22123456714')->create(['last_name' => 'Okafor']);
        Merchant::factory()->create(['last_name' => 'Adeyemi']);

        // A searchable BVN would let anyone holding merchants.view confirm
        // whether a given number is registered — precisely what the separate
        // sensitive permission exists to prevent.
        $results = $this->getJson('/api/v1/admin/merchants?search=22123456714')
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function changing_an_identity_number_is_audited_without_capturing_it(): void
    {
        $this->actingAsRole(Role::SuperAdministrator, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->withBvn('22123456714')->create();

        $this->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
            'bvn' => '33987654321',
        ])->assertOk();

        $entry = AuditLog::query()->where('action', 'merchant.sensitive_update')->firstOrFail();

        $this->assertArrayHasKey('bvn', $entry->new_values);
        $this->assertSame('[changed]', $entry->new_values['bvn']);
        $this->assertStringNotContainsString('33987654321', $entry->toJson());
        $this->assertStringNotContainsString('22123456714', $entry->toJson());
    }

    #[Test]
    public function the_index_is_updated_whenever_the_number_changes(): void
    {
        $this->actingAsRole(Role::SuperAdministrator, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->withBvn('22123456714')->create();

        $this->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
            'bvn' => '33987654321',
        ])->assertOk();

        // A stale index would silently break duplicate detection — the same
        // person could then be onboarded twice.
        $this->assertSame(
            BlindIndex::hash('33987654321', Merchant::BVN_INDEX_DOMAIN),
            $merchant->fresh()->bvn_index,
        );

        $this->assertTrue(Merchant::query()->withBvnIndex('33987654321')->exists());
        $this->assertFalse(Merchant::query()->withBvnIndex('22123456714')->exists());
    }

    #[Test]
    public function an_identity_number_must_be_eleven_digits(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        foreach (['123', '2212345671', '221234567145', 'abcdefghijk'] as $invalid) {
            $this->postJson('/api/v1/admin/merchants', [
                'first_name' => 'Test', 'last_name' => 'Person',
                'phone' => '08031234567', 'bvn' => $invalid,
            ])->assertStatus(422)->assertJsonStructure(['errors' => ['bvn']]);
        }
    }

    #[Test]
    public function only_three_roles_may_see_unmasked_numbers(): void
    {
        $this->seedRolesAndPermissions();

        $permitted = [];

        foreach (Role::cases() as $role) {
            $staff = Staff::factory()->create();
            $staff->assignRole($role->value);

            if ($staff->fresh()->hasPermissionTo(Permission::MerchantsViewSensitive->value)) {
                $permitted[] = $role->value;
            }
        }

        $this->assertEqualsCanonicalizing(
            ['super-administrator', 'credit-manager', 'compliance-officer'],
            $permitted,
        );
    }
}
