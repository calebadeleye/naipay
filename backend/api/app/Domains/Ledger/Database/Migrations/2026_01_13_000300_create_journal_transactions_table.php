<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A journal transaction — one financial event, expressed as a set of balanced
 * debit and credit entries.
 *
 * Immutable once posted. There is no update path for a posted transaction
 * anywhere in the application; a correction is a second, independent
 * transaction that reverses it. See JournalTransaction::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_transactions', function (Blueprint $table): void {
            $table->id();

            $table->string('transaction_reference', 40)->unique();
            $table->string('transaction_type', 60);

            // What caused this posting — a Loan, a Repayment, a manual
            // adjustment. Polymorphic-shaped, but queried directly rather than
            // through Eloquent's morph relation, since a transaction may
            // legitimately have no source (a manual journal entry).
            $table->string('source_type', 160)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('description', 500);
            $table->string('currency', 3)->default('NGN');

            // The date the economic event happened, and the date it was
            // recorded — usually the same, but a backdated correction can
            // differ, and accounting-period enforcement checks the latter.
            $table->date('transaction_date');
            $table->date('posting_date');

            $table->string('status', 20)->default('posted');

            /*
             * Guards against posting the same financial event twice — a
             * retried disbursement request, a webhook delivered more than
             * once. When supplied, a second post() call with the same key
             * returns the original transaction rather than creating another.
             */
            $table->string('idempotency_key', 191)->nullable()->unique();

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();

            // Set only for a transaction that itself required sign-off beyond
            // the authorisation already implied by its source — most postings
            // are the automatic consequence of an already-approved action
            // (a disbursement, an approved repayment) and this stays null.
            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();

            // Reversal linkage. `reversal_of_id` is set on a reversing
            // transaction, pointing at what it reverses; `reversed_by_id` is
            // set on the original once reversed, pointing at the reversal.
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_transactions')->nullOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('journal_transactions')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            // Written once; there is no updated_at because there is no update.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['source_type', 'source_id']);
            $table->index('transaction_type');
            $table->index(['posting_date', 'status']);
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_transactions');
    }
};
