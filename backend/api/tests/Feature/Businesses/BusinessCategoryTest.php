<?php

declare(strict_types=1);

namespace Tests\Feature\Businesses;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Businesses\Database\Seeders\BusinessCategorySeeder;
use App\Domains\Businesses\Enums\CategoryStatus;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Identity\Enums\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BusinessCategoryTest extends TestCase
{
    use RefreshDatabase;

    // --- The seeded vocabulary ---------------------------------------------

    #[Test]
    public function the_full_category_vocabulary_is_seeded(): void
    {
        $this->seed(BusinessCategorySeeder::class);

        // Every category named in the brief, plus subcategories where the
        // distinction changes how a business is assessed.
        $this->assertSame(41, BusinessCategory::query()->roots()->count());
        $this->assertGreaterThan(100, BusinessCategory::query()->whereNotNull('parent_id')->count());

        foreach ([
            'Agriculture and Farming',
            'Retail and General Trading',
            'Oil and Gas Services',
            'Renewable Energy',
            'Other Approved Business Category',
        ] as $expected) {
            $this->assertDatabaseHas('business_categories', ['name' => $expected, 'parent_id' => null]);
        }
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $this->seed(BusinessCategorySeeder::class);
        $first = BusinessCategory::query()->count();

        // It runs on every deploy.
        $this->seed(BusinessCategorySeeder::class);

        $this->assertSame($first, BusinessCategory::query()->count());
    }

    #[Test]
    public function a_reseed_never_reactivates_a_withdrawn_category(): void
    {
        $this->seed(BusinessCategorySeeder::class);

        $category = BusinessCategory::query()->where('name', 'Insurance Services')->firstOrFail();
        $category->forceFill(['status' => CategoryStatus::Inactive->value])->save();

        $this->seed(BusinessCategorySeeder::class);

        // Withdrawing a category is an administrative decision recorded in the
        // audit trail; a deploy must not silently undo it.
        $this->assertSame(CategoryStatus::Inactive, $category->fresh()->status);
    }

    #[Test]
    public function a_subcategory_slug_does_not_collide_with_a_root_of_the_same_name(): void
    {
        $this->seed(BusinessCategorySeeder::class);

        // "Livestock and Poultry" exists both as a top-level category and
        // beneath Agriculture and Farming.
        $matches = BusinessCategory::query()->where('name', 'Livestock and Poultry')->get();

        $this->assertCount(2, $matches);
        $this->assertCount(2, $matches->pluck('slug')->unique());
    }

    // --- The onboarding picker ---------------------------------------------

    #[Test]
    public function the_options_endpoint_returns_a_two_level_tree_of_active_categories(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(BusinessCategorySeeder::class);

        $tree = $this->getJson('/api/v1/admin/business-categories/options')
            ->assertOk()
            ->json('data');

        $this->assertCount(41, $tree);
        $this->assertArrayHasKey('value', $tree[0]);
        $this->assertArrayHasKey('label', $tree[0]);
        $this->assertArrayHasKey('children', $tree[0]);

        $agriculture = collect($tree)->firstWhere('label', 'Agriculture and Farming');

        $this->assertNotEmpty($agriculture['children']);
        $this->assertSame(
            'Agriculture and Farming › Crop Farming',
            $agriculture['children'][0]['qualified_label'],
        );
    }

    #[Test]
    public function the_picker_excludes_deactivated_categories(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        BusinessCategory::factory()->create(['name' => 'Selectable Category']);
        BusinessCategory::factory()->inactive()->create(['name' => 'Withdrawn Category']);

        $labels = collect($this->getJson('/api/v1/admin/business-categories/options')->json('data'))
            ->pluck('label');

        $this->assertTrue($labels->contains('Selectable Category'));
        $this->assertFalse($labels->contains('Withdrawn Category'));
    }

    #[Test]
    public function an_onboarding_officer_can_read_categories_but_not_change_them(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        $this->getJson('/api/v1/admin/business-categories/options')->assertOk();

        // The onboarding form offers no way to invent a category; the officer
        // cannot create one through the API either.
        $this->postJson('/api/v1/admin/business-categories', ['name' => 'Invented Category'])
            ->assertForbidden();

        $this->assertDatabaseMissing('business_categories', ['name' => 'Invented Category']);
    }

    // --- Administration ----------------------------------------------------

    #[Test]
    public function an_authorised_administrator_can_create_a_category(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $response = $this->postJson('/api/v1/admin/business-categories', [
            'name' => 'Waste Recycling',
            'description' => 'Collection and processing of recyclable materials.',
        ])->assertCreated();

        $this->assertSame('waste-recycling', $response->json('data.slug'));
        $this->assertTrue($response->json('data.is_root'));
        $this->assertTrue($response->json('data.is_selectable'));
    }

    #[Test]
    public function a_subcategory_can_be_created_under_a_parent(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $parent = BusinessCategory::factory()->create(['name' => 'Agriculture']);

        $response = $this->postJson('/api/v1/admin/business-categories', [
            'name' => 'Beekeeping',
            'parent_id' => $parent->id,
        ])->assertCreated();

        $this->assertFalse($response->json('data.is_root'));
        $this->assertSame('Agriculture › Beekeeping', $response->json('data.qualified_name'));
    }

    #[Test]
    public function categories_nest_only_one_level_deep(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $parent = BusinessCategory::factory()->create();
        $child = BusinessCategory::factory()->childOf($parent)->create();

        $this->postJson('/api/v1/admin/business-categories', [
            'name' => 'Too Deep',
            'parent_id' => $child->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_category_with_subcategories_cannot_become_a_subcategory(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $parent = BusinessCategory::factory()->create();
        BusinessCategory::factory()->childOf($parent)->create();
        $other = BusinessCategory::factory()->create();

        $this->patchJson("/api/v1/admin/business-categories/{$parent->id}", [
            'parent_id' => $other->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_category_cannot_be_its_own_parent(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $category = BusinessCategory::factory()->create();

        $this->patchJson("/api/v1/admin/business-categories/{$category->id}", [
            'parent_id' => $category->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_category_can_be_deactivated_and_reactivated(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $category = BusinessCategory::factory()->create();

        $this->postJson("/api/v1/admin/business-categories/{$category->id}/status", [
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.is_selectable', false);

        $this->postJson("/api/v1/admin/business-categories/{$category->id}/status", [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.is_selectable', true);
    }

    #[Test]
    public function deactivating_a_parent_deactivates_its_subcategories(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $parent = BusinessCategory::factory()->create();
        $child = BusinessCategory::factory()->childOf($parent)->create();

        $this->postJson("/api/v1/admin/business-categories/{$parent->id}/status", [
            'status' => 'inactive',
        ])->assertOk();

        // Leaving a selectable child under a withdrawn parent would put new
        // businesses back into the branch of the taxonomy just closed.
        $this->assertSame(CategoryStatus::Inactive, $child->fresh()->status);
    }

    #[Test]
    public function a_subcategory_cannot_be_activated_while_its_parent_is_inactive(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $parent = BusinessCategory::factory()->inactive()->create();
        $child = BusinessCategory::factory()->inactive()->childOf($parent)->create();

        $this->postJson("/api/v1/admin/business-categories/{$child->id}/status", [
            'status' => 'active',
        ])->assertStatus(422);
    }

    #[Test]
    public function there_is_no_endpoint_to_delete_a_category(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $category = BusinessCategory::factory()->create();

        // Businesses already filed under a category keep their classification,
        // and historical reports stay comparable.
        $this->deleteJson("/api/v1/admin/business-categories/{$category->id}")->assertStatus(405);

        $this->assertDatabaseHas('business_categories', ['id' => $category->id]);
    }

    #[Test]
    public function categories_can_be_reordered_within_a_level(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $first = BusinessCategory::factory()->create(['display_order' => 10]);
        $second = BusinessCategory::factory()->create(['display_order' => 20]);
        $third = BusinessCategory::factory()->create(['display_order' => 30]);

        $this->postJson('/api/v1/admin/business-categories/reorder', [
            'parent_id' => null,
            'ordered_ids' => [$third->id, $first->id, $second->id],
        ])->assertOk();

        $this->assertSame(10, $third->fresh()->display_order);
        $this->assertSame(20, $first->fresh()->display_order);
        $this->assertSame(30, $second->fresh()->display_order);
    }

    #[Test]
    public function reordering_cannot_move_a_category_to_another_level(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $parent = BusinessCategory::factory()->create();
        $child = BusinessCategory::factory()->childOf($parent)->create();
        $root = BusinessCategory::factory()->create();

        // Re-parenting is a distinct operation; a reorder must not smuggle it.
        $this->postJson('/api/v1/admin/business-categories/reorder', [
            'parent_id' => null,
            'ordered_ids' => [$root->id, $child->id],
        ])->assertStatus(422);
    }

    #[Test]
    public function category_changes_are_audited(): void
    {
        $actor = $this->actingAsRole(Role::SuperAdministrator);

        $this->postJson('/api/v1/admin/business-categories', ['name' => 'Audited Category'])
            ->assertCreated();

        $entry = AuditLog::query()->where('action', 'category.created')->firstOrFail();

        $this->assertSame($actor->id, $entry->staff_id);
        $this->assertSame('business_categories', $entry->module);
    }

    #[Test]
    public function two_categories_cannot_share_a_name_at_the_same_level(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $this->postJson('/api/v1/admin/business-categories', ['name' => 'Duplicate'])->assertCreated();

        // Duplicates are exactly what makes a controlled vocabulary stop being
        // controlled, so this is refused as a field error.
        $this->postJson('/api/v1/admin/business-categories', ['name' => 'Duplicate'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->assertSame(1, BusinessCategory::query()->where('name', 'Duplicate')->count());
    }

    #[Test]
    public function duplicate_root_names_are_refused_by_the_database_too(): void
    {
        // A plain unique on (parent_id, name) would not hold at the top level:
        // MySQL treats every NULL as distinct, so any number of root categories
        // could share a name. The constraint covers a generated column that
        // collapses NULL to 0, so it applies at every level.
        BusinessCategory::factory()->create(['name' => 'Root Level', 'parent_id' => null]);

        $this->expectException(QueryException::class);

        BusinessCategory::factory()->create(['name' => 'Root Level', 'parent_id' => null]);
    }

    #[Test]
    public function the_same_name_may_be_reused_under_a_different_parent(): void
    {
        $first = BusinessCategory::factory()->create();
        $second = BusinessCategory::factory()->create();

        BusinessCategory::factory()->childOf($first)->create(['name' => 'Retail']);
        BusinessCategory::factory()->childOf($second)->create(['name' => 'Retail']);

        $this->assertSame(2, BusinessCategory::query()->where('name', 'Retail')->count());
    }
}
