<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A running balance per ledger account.
 *
 * A cache, not a source of truth: the true balance of any account is always
 * derivable by summing its journal_entries, and reconciliation should do
 * exactly that from time to time to prove this table has not drifted. It
 * exists so a balance can be read without scanning the entire journal, and it
 * is maintained transactionally, under a row lock, by the same posting that
 * writes the entries — never independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_balances', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ledger_account_id')->unique()->constrained('ledger_accounts')->cascadeOnDelete();

            $table->decimal('total_debits', 20, Money::SCALE)->default(0);
            $table->decimal('total_credits', 20, Money::SCALE)->default(0);

            // total_debits − total_credits. Positive on a debit-normal
            // account with a healthy balance; the sign is interpreted against
            // the account's type when displayed, never re-derived by a caller.
            $table->decimal('balance', 20, Money::SCALE)->default(0);

            $table->foreignId('last_journal_transaction_id')->nullable()
                ->constrained('journal_transactions')->nullOnDelete();

            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');
    }
};
