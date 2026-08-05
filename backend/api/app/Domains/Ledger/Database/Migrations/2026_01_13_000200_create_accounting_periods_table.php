<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting periods.
 *
 * Defining a period and closing it is optional finance-team housekeeping, not
 * a prerequisite for posting: a posting date that falls outside any defined
 * period is simply not constrained by one. A posting date that falls inside a
 * *closed* period is refused — that is the entire point of closing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 60);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');

            $table->foreignId('closed_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->unique(['starts_on', 'ends_on']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
