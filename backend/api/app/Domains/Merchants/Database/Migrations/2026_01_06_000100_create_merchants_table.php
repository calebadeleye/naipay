<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchants — the individual or principal account holder.
 *
 * A merchant is deliberately separate from the business they operate. One
 * person may run several enterprises, and the credit relationship, identity
 * verification and contact details belong to the person, not the shop.
 * Collapsing the two would make a second business impossible to add without a
 * migration and a data cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table): void {
            $table->id();

            $table->string('merchant_number', 32)->unique();

            // --- Personal details --------------------------------------------
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('employment_status', 40)->nullable();
            $table->string('preferred_language', 40)->nullable();

            // --- Contact ------------------------------------------------------
            $table->string('phone', 20);
            $table->string('alternative_phone', 20)->nullable();
            $table->string('email', 190)->nullable();

            $table->string('residential_address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('Nigeria');

            /*
             * --- Identity numbers ---------------------------------------------
             *
             * BVN and NIN are encrypted at rest, so the ciphertext column is
             * long and unsearchable. Each is paired with a blind index — an
             * HMAC of the value — which is what uniqueness and lookup run
             * against. See App\Support\Security\BlindIndex.
             *
             * The plaintext is only ever decrypted for staff holding
             * merchants.view_sensitive; everyone else receives 22*******14.
             */
            $table->text('bvn')->nullable();
            $table->char('bvn_index', 64)->nullable()->unique();

            $table->text('nin')->nullable();
            $table->char('nin_index', 64)->nullable()->unique();

            $table->string('profile_photo', 500)->nullable();

            // --- Relationship management --------------------------------------
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('assigned_officer_id')->nullable()->constrained('staff')->nullOnDelete();

            $table->string('risk_rating', 20)->nullable();
            $table->string('kyc_status', 30)->default('not_started');
            $table->string('merchant_status', 30)->default('inactive');
            $table->string('onboarding_status', 30)->default('draft');

            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();

            // --- Custody -------------------------------------------------------
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // Merchants are referenced by every loan and repayment in their
            // history; they are closed, never removed.
            $table->softDeletes();

            $table->index('onboarding_status');
            $table->index('merchant_status');
            $table->index('kyc_status');
            $table->index(['branch_id', 'onboarding_status']);
            $table->index('assigned_officer_id');
            $table->index('phone');
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
