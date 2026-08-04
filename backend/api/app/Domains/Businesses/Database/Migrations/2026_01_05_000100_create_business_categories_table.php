<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business categories, as a controlled vocabulary.
 *
 * Deliberately not free text on the merchant form. Free-text categories become
 * "Provisions", "provision store", "Prov. Store" and "Retail (provisions)"
 * within a month, and every category-based report, risk model and portfolio
 * breakdown is worthless from then on. Onboarding officers pick from this list;
 * only authorised administrators may extend it.
 *
 * Two levels: a parent category and its subcategories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_categories', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();

            /*
             * Parent category. Restricted rather than cascading on delete: a
             * parent with children must not be removable, or its subcategories
             * would vanish along with the classification of every business
             * filed under them.
             */
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('business_categories')
                ->restrictOnDelete();

            $table->string('icon', 60)->nullable();

            $table->string('status', 20)->default('active');

            // Manual ordering, so the categories an officer reaches for most
            // sit at the top rather than being buried alphabetically.
            $table->unsignedInteger('display_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->timestamps();

            /*
             * No soft deletes, and no delete endpoint.
             *
             * A category is deactivated, never removed: businesses already
             * filed under it keep their classification, and historical reports
             * stay comparable. Deactivation only removes it from the picker.
             */

            $table->index(['status', 'display_order']);
            $table->index('parent_id');

            /*
             * Uniqueness of a name within its level.
             *
             * A plain unique on (parent_id, name) would not hold at the top
             * level: MySQL treats each NULL as distinct, so any number of root
             * categories could share a name — which is precisely how a
             * controlled vocabulary stops being controlled.
             *
             * The generated column collapses NULL to 0 so root categories
             * compare against each other like any other level.
             */
            $table->unsignedBigInteger('parent_key')
                ->storedAs('COALESCE(parent_id, 0)');

            $table->unique(['parent_key', 'name'], 'business_categories_level_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_categories');
    }
};
