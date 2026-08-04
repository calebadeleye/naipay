<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches — the organisational unit merchants, loans and staff belong to, and
 * the boundary most access scoping is drawn around.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();

            $table->string('branch_code', 20)->unique();
            $table->string('name', 150);

            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('Nigeria');

            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();

            /*
             * The branch manager.
             *
             * Nullable and nulled on delete: a branch must not become
             * unreachable because its manager's record was removed, and a
             * branch legitimately sits without one between appointments.
             *
             * Added as a foreign key in a later migration — `staff` has no
             * branch column yet, and constraining in both directions here
             * would make the two tables un-creatable in any order.
             */
            $table->foreignId('manager_id')->nullable();

            $table->string('status', 32)->default('active');

            $table->date('opened_at')->nullable();
            $table->date('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->timestamps();

            // Branches are referenced by every loan and repayment ever booked
            // against them, so they are closed, never deleted.
            $table->softDeletes();

            $table->index('status');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
