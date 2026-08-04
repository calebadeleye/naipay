<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable audit trail.
 *
 * Introduced here rather than in the audit phase because staff management is
 * the first thing that needs it, and building a separate "staff activity" log
 * now would mean two competing histories to reconcile later. The audit phase
 * extends this with the viewer, exports and system-wide coverage; the table and
 * its guarantees are settled here.
 *
 * Append-only. Nothing in the application updates or deletes these rows, and no
 * permission to do so exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            // Null for actions taken by the system itself — the scheduler
            // ageing a loan, a queued job posting interest.
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();

            // Denormalised so the trail stays readable after a staff member is
            // renamed, or their record is soft-deleted years later.
            $table->string('actor_name', 200)->nullable();
            $table->string('actor_roles', 400)->nullable();

            $table->string('action', 100);
            $table->string('module', 60);
            $table->string('event_type', 60);

            // The record acted upon.
            $table->string('auditable_type', 160)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            // Human-readable identifier — NPM-000001, NPL-2026-000042 — so an
            // entry means something without resolving the record.
            $table->string('auditable_reference', 64)->nullable();

            // Only the attributes that actually changed, already scrubbed of
            // credentials and identity numbers.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Required for reversals, write-offs, rejections and suspensions.
            $table->text('reason')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->uuid('correlation_id')->nullable();

            // Written once; there is no updated_at because there is no update.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_index');
            $table->index(['staff_id', 'created_at']);
            $table->index(['module', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('correlation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
