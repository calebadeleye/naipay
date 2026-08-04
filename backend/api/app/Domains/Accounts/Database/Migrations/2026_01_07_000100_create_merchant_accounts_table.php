<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A merchant's internal financial account.
 *
 * Created when a merchant is approved — the last step of onboarding. This is
 * the identifier a merchant is given, and what a virtual account will later be
 * mapped onto when payment providers are integrated.
 *
 * The balance columns here are a cached view for fast display. The ledger is
 * the source of financial truth, and the two are reconciled against each other;
 * nothing computes a balance from this table alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_accounts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();

            /*
             * Ten random digits, matching the NUBAN length merchants already
             * recognise. Random rather than sequential so the number discloses
             * neither the size of the book nor its neighbours.
             */
            $table->char('account_number', 10)->unique();

            $table->string('account_name', 200);
            $table->string('account_type', 30)->default('merchant_wallet');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 30)->default('active');

            // Cached for display. DECIMAL(20,2) like every monetary column.
            $table->decimal('available_balance', 20, Money::SCALE)->default(0);
            $table->decimal('ledger_balance', 20, Money::SCALE)->default(0);

            // When the cached balances were last agreed with the ledger.
            $table->timestamp('balances_reconciled_at')->nullable();

            $table->foreignId('opened_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index('merchant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_accounts');
    }
};
