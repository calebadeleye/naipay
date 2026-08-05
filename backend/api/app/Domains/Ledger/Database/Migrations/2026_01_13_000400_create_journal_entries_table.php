<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a journal transaction — a single debit or credit against a
 * single account.
 *
 * Never updated and never deleted after the transaction that owns it is
 * posted; correcting one means reversing the whole transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('journal_transaction_id')->constrained('journal_transactions')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();

            $table->decimal('debit_amount', 20, Money::SCALE)->default(0);
            $table->decimal('credit_amount', 20, Money::SCALE)->default(0);

            $table->string('description', 500)->nullable();

            // Reporting dimensions. Nullable — a general operating expense has
            // no merchant to attribute it to — but populated wherever the
            // posting genuinely concerns one of these.
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('loan_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Written once, alongside its parent transaction.
            $table->timestamp('created_at')->useCurrent();

            $table->index('ledger_account_id');
            $table->index('merchant_id');
            $table->index('loan_id');
            $table->index('branch_id');
        });

        /*
         * Exactly one side of each line carries an amount. Enforced by the
         * database, not only by the posting service — a defence-in-depth
         * measure against anything that writes to this table directly.
         *
         * Laravel's schema builder has no fluent CHECK constraint helper, so
         * this is added with raw SQL after the table exists. Supported from
         * MySQL 8.0.16 onward, which every environment this runs on meets.
         */
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_one_sided_amount '
            .'CHECK ((debit_amount = 0 OR credit_amount = 0) AND (debit_amount > 0 OR credit_amount > 0))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
