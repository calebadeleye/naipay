<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Businesses — the commercial enterprise a merchant owns or operates.
 *
 * A merchant may have one business today and several later; the relationship is
 * one-to-many from the outset so adding the second needs no migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();

            $table->string('business_number', 32)->unique();

            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();

            /*
             * Category is a foreign key into the controlled vocabulary, never
             * free text. Restricted on delete — though categories are only ever
             * deactivated — so a business can never be left pointing at a
             * category that no longer exists.
             */
            $table->foreignId('business_category_id')
                ->constrained('business_categories')
                ->restrictOnDelete();

            $table->foreignId('business_subcategory_id')
                ->nullable()
                ->constrained('business_categories')
                ->restrictOnDelete();

            // --- Identity ------------------------------------------------------
            $table->string('business_name', 200);
            $table->string('registered_business_name', 200)->nullable();
            $table->string('cac_registration_number', 40)->nullable();
            $table->string('business_type', 60);
            $table->text('business_description')->nullable();

            // --- Contact -------------------------------------------------------
            $table->string('business_phone', 20)->nullable();
            $table->string('business_email', 190)->nullable();
            $table->string('business_address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('Nigeria');
            $table->string('business_website', 255)->nullable();
            $table->json('social_media_links')->nullable();

            // Field verification captures where the shop actually is.
            $table->decimal('gps_latitude', 10, 7)->nullable();
            $table->decimal('gps_longitude', 10, 7)->nullable();

            // --- Trading profile -------------------------------------------------
            $table->year('year_established')->nullable();
            $table->unsignedInteger('number_of_employees')->nullable();

            /*
             * Monetary figures are DECIMAL(20,2) like every other amount in the
             * system, even though these are merchant-declared estimates rather
             * than ledger values. They feed repayment-capacity assessment, and
             * a float here would produce a different affordability answer on
             * different runs.
             */
            $table->decimal('estimated_monthly_revenue', 20, Money::SCALE)->nullable();
            $table->decimal('estimated_monthly_expenses', 20, Money::SCALE)->nullable();
            $table->decimal('average_monthly_sales', 20, Money::SCALE)->nullable();

            // --- Status ----------------------------------------------------------
            $table->string('verification_status', 30)->default('unverified');
            $table->string('status', 30)->default('inactive');

            /*
             * --- Business connections (future) ------------------------------------
             *
             * Reserved for the merchant directory, supplier discovery and
             * business-to-business features. Defaulted off and not exposed by
             * any endpoint in this release: a public profile must never appear
             * without the merchant's explicit consent, and the safest way to
             * guarantee that is for the default to be "no".
             */
            $table->boolean('public_profile_enabled')->default(false);
            $table->boolean('accepts_business_connections')->default(false);
            $table->json('connection_preferences')->nullable();
            $table->json('service_areas')->nullable();
            $table->json('products_and_services')->nullable();
            $table->string('business_visibility', 30)->default('private');

            // --- Custody ----------------------------------------------------------
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('merchant_id');
            $table->index('business_category_id');
            $table->index('status');
            $table->index('verification_status');
            $table->index('business_name');
            $table->index('state');
            $table->index('cac_registration_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
