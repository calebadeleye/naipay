<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Places staff within the organisation: their branch, the breadth of their
 * access, their department, and how much they may approve.
 *
 * Kept separate from the staff table's creation so the identity phase remains
 * independently deployable — authentication works with or without any of this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('job_title')
                ->constrained('branches')
                ->nullOnDelete();

            /*
             * How far a staff member can see.
             *
             *   branch     — their own branch only (the default)
             *   department — their department across every branch
             *   global     — the whole organisation
             *
             * Separate from permissions on purpose: a Branch Manager and an
             * Operations Manager may hold the same permissions and legitimately
             * differ in reach.
             */
            $table->string('access_scope', 20)->default('branch')->after('branch_id');
            $table->string('department', 60)->nullable()->after('access_scope');

            /*
             * The most this staff member may approve, in Naira.
             *
             * DECIMAL(20,2) like every monetary column. Null means no approval
             * authority at all, which is deliberately distinct from zero.
             */
            $table->decimal('approval_limit', 20, Money::SCALE)->nullable()->after('department');

            $table->text('suspension_reason')->nullable()->after('locked_until');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->foreignId('suspended_by')->nullable()->after('suspended_at')
                ->constrained('staff')->nullOnDelete();

            $table->index('branch_id');
            $table->index('access_scope');
            $table->index('department');
        });

        // Now that staff.branch_id exists, the branch manager reference can be
        // constrained in the other direction.
        Schema::table('branches', function (Blueprint $table): void {
            $table->foreign('manager_id')->references('id')->on('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropForeign(['manager_id']);
        });

        Schema::table('staff', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('suspended_by');

            $table->dropIndex(['access_scope']);
            $table->dropIndex(['department']);

            $table->dropColumn([
                'access_scope',
                'department',
                'approval_limit',
                'suspension_reason',
                'suspended_at',
            ]);
        });
    }
};
