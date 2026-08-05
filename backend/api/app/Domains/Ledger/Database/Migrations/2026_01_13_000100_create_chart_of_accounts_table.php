<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts — every account the ledger can post to.
 *
 * Naipay's financial truth lives here, not in the balance columns cached on a
 * loan or a merchant account. Those are a fast view for display; this, and the
 * journal entries posted against it, are what is actually reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();

            // A short, stable code — "1100" for Loan Principal Receivable —
            // that domain services reference directly, so a display-name
            // rename never breaks a posting.
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->string('type', 20);

            /*
             * Seeded, structural accounts that the standard chart of accounts
             * depends on — Cash at Bank, Loan Principal Receivable, and so on.
             * Protected from deletion and from having their type changed
             * underneath postings that already reference them; an
             * administrator may still deactivate one that is no longer
             * wanted.
             */
            $table->boolean('is_system')->default(false);

            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
