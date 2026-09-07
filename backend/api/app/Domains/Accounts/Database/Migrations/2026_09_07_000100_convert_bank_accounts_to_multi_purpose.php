<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A designated bank account may serve more than one purpose at once — the
 * same account is very often both where repayments are collected and where
 * disbursements are funded from. The single `account_purpose` column could
 * not express that, so disbursing (or collecting) through an account whose
 * one recorded purpose happened to be the other use was wrongly rejected as
 * "not designated for" it.
 *
 * `account_purpose` (one value) becomes `purposes` (a JSON array of one or
 * more BankAccountPurpose values). Existing rows are carried over as a
 * single-element array so nothing already approved changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->json('purposes')->nullable()->after('currency');
        });

        // Carry every existing account's single purpose across unchanged.
        // Done in PHP rather than one UPDATE so it behaves identically on
        // every database this runs against, including the test MySQL.
        DB::table('bank_accounts')->select('id', 'account_purpose')->orderBy('id')
            ->each(function (object $row): void {
                DB::table('bank_accounts')
                    ->where('id', $row->id)
                    ->update(['purposes' => json_encode([$row->account_purpose])]);
            });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            // ['account_purpose', 'status'] — replaced by a plain status
            // index, since "which accounts are for purpose X" is now a JSON
            // containment check the composite could not serve anyway.
            $table->dropIndex(['account_purpose', 'status']);
            $table->dropColumn('account_purpose');
            $table->index('status');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->json('purposes')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->string('account_purpose', 40)->nullable()->after('currency');
        });

        // The first purpose is the one that was there before this migration.
        DB::table('bank_accounts')->select('id', 'purposes')->orderBy('id')
            ->each(function (object $row): void {
                /** @var list<string> $purposes */
                $purposes = json_decode((string) $row->purposes, true) ?: ['operating_account'];

                DB::table('bank_accounts')
                    ->where('id', $row->id)
                    ->update(['account_purpose' => $purposes[0]]);
            });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->string('account_purpose', 40)->nullable(false)->change();
            $table->dropIndex(['status']);
            $table->dropColumn('purposes');
            $table->index(['account_purpose', 'status']);
        });
    }
};
