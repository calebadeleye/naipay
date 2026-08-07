<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member's in-app notification inbox — the bell in the admin
 * console's topbar.
 *
 * `type` reuses the same operation-key vocabulary as
 * `RequiresMakerChecker::makerCheckerOperation()` (e.g. `repayment.approve`),
 * so a notification's origin is traceable without a separate taxonomy.
 * `subject_type`/`subject_id` link back to the record the notification is
 * about, so the frontend can deep-link without the backend needing to know
 * every possible frontend route shape — `action_url` already carries that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();

            $table->string('type', 100);
            $table->string('title', 255);
            $table->text('body')->nullable();

            $table->nullableMorphs('subject');
            $table->string('action_url')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
