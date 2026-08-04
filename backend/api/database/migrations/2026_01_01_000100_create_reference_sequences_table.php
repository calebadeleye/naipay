<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counters behind every human-readable Naipay reference (NPM-000001,
 * NPL-2026-000001, and so on).
 *
 * One row per sequence per period. `period` is the year for references that
 * reset annually and a constant for those that run continuously, which keeps a
 * single primary key covering both cases.
 *
 * The table deliberately has no auto-increment surrogate key. Allocation reads
 * its result back through MySQL's LAST_INSERT_ID(expr), and an AUTO_INCREMENT
 * column on this table would also write to that same session value on insert,
 * making the counter and the row id indistinguishable. The natural key alone
 * removes the ambiguity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_sequences', function (Blueprint $table): void {
            $table->string('name', 64);
            $table->string('period', 16);

            // Unsigned: a reference counter never moves backwards.
            $table->unsignedBigInteger('current_value')->default(0);

            $table->timestamps();

            // The allocation statement relies on this being the unique key —
            // it is what makes ON DUPLICATE KEY UPDATE atomic.
            $table->primary(['name', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
    }
};
