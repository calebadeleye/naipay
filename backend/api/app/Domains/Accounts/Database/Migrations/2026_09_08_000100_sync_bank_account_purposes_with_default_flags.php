<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Being the default disbursement (or collection) account is itself a
 * designation for that flow, but until now the flag and the `purposes` set
 * could diverge: an account could be made the default disbursement account
 * without `loan_disbursement` in its purposes, and disbursing through it was
 * then wrongly rejected as "not designated for loan disbursement".
 *
 * BankAccountService::setAsDefault() now keeps the two in step. This heals
 * the rows created before that.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bank_accounts')
            ->select('id', 'purposes', 'is_default_collection_account', 'is_default_disbursement_account')
            ->orderBy('id')
            ->each(function (object $row): void {
                /** @var list<string> $purposes */
                $purposes = json_decode((string) $row->purposes, true) ?: [];

                if ($row->is_default_collection_account && ! in_array('loan_repayment_collection', $purposes, true)) {
                    $purposes[] = 'loan_repayment_collection';
                }

                if ($row->is_default_disbursement_account && ! in_array('loan_disbursement', $purposes, true)) {
                    $purposes[] = 'loan_disbursement';
                }

                DB::table('bank_accounts')
                    ->where('id', $row->id)
                    ->update(['purposes' => json_encode(array_values($purposes))]);
            });
    }

    public function down(): void
    {
        // A purpose added here is indistinguishable from one that was always
        // present, so there is nothing safe to remove.
    }
};
