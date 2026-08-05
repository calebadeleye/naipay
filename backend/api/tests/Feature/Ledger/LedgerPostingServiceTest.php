<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Data\JournalLine;
use App\Domains\Ledger\Database\Seeders\ChartOfAccountsSeeder;
use App\Domains\Ledger\Enums\AccountingPeriodStatus;
use App\Domains\Ledger\Enums\JournalTransactionStatus;
use App\Domains\Ledger\Models\AccountingPeriod;
use App\Domains\Ledger\Models\JournalEntry;
use App\Domains\Ledger\Models\JournalTransaction;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Domains\Ledger\Services\LedgerPostingService;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\FinancialIntegrityException;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The double-entry ledger.
 *
 * This is where financial truth lives. Every guarantee here is load-bearing:
 * a bug that lets debits and credits drift, or lets a posting happen twice,
 * or lets a posted entry be quietly edited, produces a book that cannot be
 * trusted — and the whole point of building a ledger rather than trusting
 * cached balance columns is that it can be.
 */
final class LedgerPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
    }

    // --- Balance validation --------------------------------------------------

    #[Test]
    public function a_balanced_posting_succeeds(): void
    {
        $transaction = $this->ledger()->post(
            transactionType: 'test',
            description: 'A balanced posting.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100000.00')),
            ],
        );

        $this->assertSame(JournalTransactionStatus::Posted, $transaction->status);
        $this->assertMatchesRegularExpression('/^NPJ-\d{4}-\d{6}$/', $transaction->transaction_reference);
        $this->assertCount(2, $transaction->entries);
    }

    #[Test]
    public function an_unbalanced_posting_is_refused(): void
    {
        $this->expectException(FinancialIntegrityException::class);

        $this->ledger()->post(
            transactionType: 'test',
            description: 'Deliberately unbalanced.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('99999.99')),
            ],
        );
    }

    #[Test]
    public function a_posting_a_single_kobo_out_of_balance_is_refused(): void
    {
        // The threshold that matters: not "close enough", exact.
        $this->expectException(FinancialIntegrityException::class);

        $this->ledger()->post(
            transactionType: 'test',
            description: 'One kobo out.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100000.01')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100000.00')),
            ],
        );
    }

    #[Test]
    public function a_multi_line_posting_balances_across_several_credits(): void
    {
        // A repayment allocated across penalty, interest and principal —
        // three credits against one debit.
        $transaction = $this->ledger()->post(
            transactionType: 'test',
            description: 'Repayment allocated across three receivables.',
            lines: [
                JournalLine::debit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('15000.00')),
                JournalLine::credit(StandardAccounts::PENALTY_RECEIVABLE, Money::fromDecimal('1000.00')),
                JournalLine::credit(StandardAccounts::INTEREST_RECEIVABLE, Money::fromDecimal('4000.00')),
                JournalLine::credit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('10000.00')),
            ],
        );

        $this->assertCount(4, $transaction->entries);
    }

    #[Test]
    public function a_posting_needs_at_least_two_lines(): void
    {
        $this->expectException(FinancialIntegrityException::class);

        $this->ledger()->post(
            transactionType: 'test',
            description: 'A single line cannot balance.',
            lines: [
                JournalLine::debit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );
    }

    #[Test]
    public function a_zero_amount_line_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JournalLine::debit(StandardAccounts::CASH_AT_BANK, Money::zero());
    }

    #[Test]
    public function an_unknown_account_code_is_refused(): void
    {
        $this->expectException(DomainException::class);

        $this->ledger()->post(
            transactionType: 'test',
            description: 'References an account that does not exist.',
            lines: [
                JournalLine::debit('9999', Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );
    }

    #[Test]
    public function an_inactive_account_cannot_be_posted_to(): void
    {
        $inactive = LedgerAccount::factory()->inactive()->create();

        $this->expectException(DomainException::class);

        $this->ledger()->post(
            transactionType: 'test',
            description: 'Posting to a retired account.',
            lines: [
                JournalLine::debit($inactive->code, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );
    }

    // --- Balances --------------------------------------------------------------

    #[Test]
    public function posting_updates_the_account_balance_cache(): void
    {
        $this->ledger()->post(
            transactionType: 'test',
            description: 'First posting.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('50000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('50000.00')),
            ],
        );

        $receivable = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();
        $cash = LedgerAccount::query()->where('code', StandardAccounts::CASH_AT_BANK)->firstOrFail();

        // Debit-normal: the receivable's balance reads positive as money owed.
        $this->assertSame('50000.00', $receivable->fresh()->currentBalance()->toDecimalString());

        // Credit-normal: cash going out is a credit, so the account's own
        // "money in the bank" reading falls — represented as negative here
        // because nothing was ever deposited in this test.
        $this->assertSame('-50000.00', $cash->fresh()->currentBalance()->toDecimalString());
    }

    #[Test]
    public function balances_accumulate_across_multiple_postings(): void
    {
        $ledger = $this->ledger();

        $ledger->post(
            transactionType: 'test', description: 'First loan.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100000.00')),
            ],
        );

        $ledger->post(
            transactionType: 'test', description: 'Second loan.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('75000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('75000.00')),
            ],
        );

        $receivable = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();

        $this->assertSame('175000.00', $receivable->currentBalance()->toDecimalString());
    }

    #[Test]
    public function the_balance_cache_agrees_with_summing_every_entry(): void
    {
        // The cache exists for speed; it must never disagree with the entries
        // it is a cache of.
        $ledger = $this->ledger();

        foreach (['12345.67', '999.01', '55000.00'] as $amount) {
            $ledger->post(
                transactionType: 'test', description: 'Reconciliation check.',
                lines: [
                    JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal($amount)),
                    JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal($amount)),
                ],
            );
        }

        $account = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();

        $summedFromEntries = Money::sum(
            JournalEntry::query()->where('ledger_account_id', $account->id)->get()
                ->map(fn (JournalEntry $e): Money => $e->debit_amount->minus($e->credit_amount))
                ->all(),
        );

        $this->assertTrue($summedFromEntries->equals($account->fresh()->currentBalance()));
    }

    // --- Immutability ------------------------------------------------------------

    #[Test]
    public function a_posted_transaction_cannot_be_edited(): void
    {
        $transaction = $this->ledger()->post(
            transactionType: 'test', description: 'Immutable once posted.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $transaction->update(['description' => 'Tampered.']);
    }

    #[Test]
    public function a_posted_transaction_cannot_be_deleted(): void
    {
        $transaction = $this->ledger()->post(
            transactionType: 'test', description: 'Permanent.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permanent');

        $transaction->delete();
    }

    #[Test]
    public function a_journal_entry_cannot_be_edited_or_deleted(): void
    {
        $transaction = $this->ledger()->post(
            transactionType: 'test', description: 'Entries are immutable too.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );

        $entry = $transaction->entries->first();

        try {
            $entry->update(['description' => 'Tampered.']);
            $this->fail('Expected an exception updating a posted journal entry.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('Expected an exception deleting a posted journal entry.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permanent', $e->getMessage());
        }
    }

    // --- Idempotency ---------------------------------------------------------------

    #[Test]
    public function the_same_idempotency_key_never_posts_twice(): void
    {
        $ledger = $this->ledger();

        $lines = [
            JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100000.00')),
            JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100000.00')),
        ];

        $first = $ledger->post(
            transactionType: 'disbursement', description: 'Loan disbursement.',
            lines: $lines, idempotencyKey: 'loan-disbursement:42',
        );

        // A retried request — same key, would otherwise double-charge the
        // receivable.
        $second = $ledger->post(
            transactionType: 'disbursement', description: 'Loan disbursement (retried).',
            lines: $lines, idempotencyKey: 'loan-disbursement:42',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalTransaction::query()->count());

        $receivable = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();
        $this->assertSame('100000.00', $receivable->currentBalance()->toDecimalString());
    }

    #[Test]
    public function the_database_refuses_a_duplicate_idempotency_key_even_bypassing_the_service(): void
    {
        // The service's pre-check is a courtesy; this unique constraint is
        // what actually holds under concurrency.
        $this->ledger()->post(
            transactionType: 'test', description: 'First.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
            idempotencyKey: 'duplicate-key',
        );

        $this->expectException(QueryException::class);

        DB::table('journal_transactions')->insert([
            'transaction_reference' => 'NPJ-2026-999999',
            'transaction_type' => 'test',
            'description' => 'Second, same key.',
            'currency' => 'NGN',
            'transaction_date' => now()->toDateString(),
            'posting_date' => now()->toDateString(),
            'status' => 'posted',
            'idempotency_key' => 'duplicate-key',
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function sequential_postings_against_the_same_account_never_lose_an_update(): void
    {
        // Eloquent models are bound to the app's single default connection,
        // so a same-process test cannot make two LedgerPostingService calls
        // race for real. What matters — that lockForUpdate() genuinely
        // serialises writers to account_balances rather than merely reading
        // stale data — is proven directly below, against raw connections,
        // without going through Eloquent's connection resolution at all.
        $ledger = $this->ledger();

        for ($i = 0; $i < 10; $i++) {
            $ledger->post(
                transactionType: 'test', description: "Posting {$i}.",
                lines: [
                    JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('1000.00')),
                    JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('1000.00')),
                ],
                idempotencyKey: "sequential:{$i}",
            );
        }

        $receivable = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();

        $this->assertSame('10000.00', $receivable->currentBalance()->toDecimalString());
    }

    #[Test]
    public function a_locked_balance_row_genuinely_blocks_a_second_writer(): void
    {
        // Proves the mechanism LedgerPostingService relies on: two separate
        // connections, a real BEGIN on each, and MySQL's own NOWAIT lock
        // check — not a mock, not a same-process race that Eloquent's single
        // shared connection would prevent from ever really occurring.
        $this->withoutTransaction(function (): void {
            // setUp()'s seeding ran inside the wrapping transaction that
            // withoutTransaction() just rolled back, so the chart of accounts
            // needs reseeding here.
            $this->seed(ChartOfAccountsSeeder::class);

            $account = LedgerAccount::query()->where('code', StandardAccounts::CASH_AT_BANK)->firstOrFail();

            DB::table('account_balances')->insert([
                'ledger_account_id' => $account->id,
                'total_debits' => '0.00',
                'total_credits' => '0.00',
                'balance' => '0.00',
                'updated_at' => now(),
            ]);

            config(['database.connections.lock_test_a' => config('database.connections.mysql')]);
            config(['database.connections.lock_test_b' => config('database.connections.mysql')]);
            $connectionA = DB::connection('lock_test_a');
            $connectionB = DB::connection('lock_test_b');

            $connectionA->beginTransaction();
            $connectionA->table('account_balances')
                ->where('ledger_account_id', $account->id)
                ->lockForUpdate()
                ->first();

            try {
                $connectionB->beginTransaction();

                $this->expectException(QueryException::class);

                // NOWAIT (MySQL 8.0+) fails immediately instead of blocking,
                // so the test does not hang if locking is broken — it simply
                // fails to throw, which the framework reports as a missed
                // expected-exception rather than a stalled test run.
                $connectionB->select(
                    'SELECT * FROM account_balances WHERE ledger_account_id = ? FOR UPDATE NOWAIT',
                    [$account->id],
                );
            } finally {
                $connectionB->rollBack();
                $connectionA->rollBack();
                $connectionA->disconnect();
                $connectionB->disconnect();
                config(['database.connections.lock_test_a' => null]);
                config(['database.connections.lock_test_b' => null]);
            }
        });
    }

    // --- Reversal -------------------------------------------------------------------

    #[Test]
    public function a_reversal_mirrors_every_line_to_the_opposite_side(): void
    {
        $staff = Staff::factory()->create();

        $original = $this->ledger()->post(
            transactionType: 'disbursement', description: 'Loan disbursement.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100000.00')),
            ],
        );

        $reversal = $this->ledger()->reverse($original, 'Disbursed against the wrong loan in error.', $staff);

        $this->assertNotSame($original->id, $reversal->id);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertTrue($reversal->isReversal());

        $original->refresh();
        $this->assertSame(JournalTransactionStatus::Reversed, $original->status);
        $this->assertSame($reversal->id, $original->reversed_by_id);
        $this->assertNotNull($original->reversed_at);
    }

    #[Test]
    public function a_reversal_restores_the_balance_to_zero(): void
    {
        $staff = Staff::factory()->create();

        $original = $this->ledger()->post(
            transactionType: 'disbursement', description: 'Loan disbursement.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('250000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('250000.00')),
            ],
        );

        $this->ledger()->reverse($original, 'Booked against the wrong merchant.', $staff);

        $receivable = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();
        $cash = LedgerAccount::query()->where('code', StandardAccounts::CASH_AT_BANK)->firstOrFail();

        $this->assertTrue($receivable->currentBalance()->isZero());
        $this->assertTrue($cash->currentBalance()->isZero());
    }

    #[Test]
    public function the_original_entries_are_never_touched_by_a_reversal(): void
    {
        // Reversal restores the balance by posting a mirror, never by editing
        // or removing what was already there — both transactions remain in
        // the journal permanently.
        $staff = Staff::factory()->create();

        $original = $this->ledger()->post(
            transactionType: 'disbursement', description: 'Loan disbursement.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100000.00')),
            ],
        );

        $originalEntryCount = $original->entries()->count();

        $this->ledger()->reverse($original, 'Correction.', $staff);

        $this->assertSame($originalEntryCount, $original->fresh()->entries()->count());
        $this->assertSame(4, JournalEntry::query()->count());
    }

    #[Test]
    public function a_transaction_cannot_be_reversed_twice(): void
    {
        $staff = Staff::factory()->create();

        $original = $this->ledger()->post(
            transactionType: 'test', description: 'Reversed once.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );

        $this->ledger()->reverse($original, 'First reversal.', $staff);

        $this->expectException(DomainException::class);

        $this->ledger()->reverse($original->fresh(), 'Attempting a second reversal.', $staff);
    }

    #[Test]
    public function a_reversal_cannot_itself_be_reversed(): void
    {
        $staff = Staff::factory()->create();

        $original = $this->ledger()->post(
            transactionType: 'test', description: 'Original.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );

        $reversal = $this->ledger()->reverse($original, 'Correction.', $staff);

        $this->expectException(DomainException::class);

        // A correction to a correction is a new posting, not a reversal of
        // the reversal.
        $this->ledger()->reverse($reversal, 'Trying to reverse the reversal.', $staff);
    }

    #[Test]
    public function reversing_twice_via_a_retried_request_is_idempotent(): void
    {
        $staff = Staff::factory()->create();

        $original = $this->ledger()->post(
            transactionType: 'test', description: 'Original.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
        );

        $first = $this->ledger()->reverse($original, 'Correction.', $staff);

        // The service's own idempotency key on the reversal ("reversal:{id}")
        // means calling post() again with the same key — bypassing the
        // already-reversed guard entirely — still cannot double-post.
        $second = app(LedgerPostingService::class)->post(
            transactionType: 'reversal',
            description: 'Retried reversal post.',
            lines: [
                JournalLine::credit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::debit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
            idempotencyKey: "reversal:{$original->id}",
        );

        $this->assertSame($first->id, $second->id);
    }

    // --- Accounting periods ------------------------------------------------------

    #[Test]
    public function posting_into_a_closed_period_is_refused(): void
    {
        config(['naipay.ledger.enforce_period_lock' => true]);

        AccountingPeriod::forceCreate([
            'name' => 'March 2026',
            'starts_on' => '2026-03-01',
            'ends_on' => '2026-03-31',
            'status' => AccountingPeriodStatus::Closed,
        ]);

        $this->expectException(DomainException::class);

        $this->ledger()->post(
            transactionType: 'test', description: 'Backdated into a closed period.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
            transactionDate: Carbon::parse('2026-03-15'),
        );
    }

    #[Test]
    public function posting_into_an_open_period_succeeds(): void
    {
        AccountingPeriod::forceCreate([
            'name' => 'April 2026',
            'starts_on' => '2026-04-01',
            'ends_on' => '2026-04-30',
            'status' => AccountingPeriodStatus::Open,
        ]);

        $transaction = $this->ledger()->post(
            transactionType: 'test', description: 'Within an open period.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
            transactionDate: Carbon::parse('2026-04-15'),
        );

        $this->assertSame(JournalTransactionStatus::Posted, $transaction->status);
    }

    #[Test]
    public function a_posting_date_outside_any_defined_period_is_unconstrained(): void
    {
        // Periods are opt-in bookkeeping, not a prerequisite for posting.
        AccountingPeriod::forceCreate([
            'name' => 'March 2026',
            'starts_on' => '2026-03-01',
            'ends_on' => '2026-03-31',
            'status' => AccountingPeriodStatus::Closed,
        ]);

        $transaction = $this->ledger()->post(
            transactionType: 'test', description: 'No period covers this date.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
            transactionDate: Carbon::parse('2026-06-01'),
        );

        $this->assertSame(JournalTransactionStatus::Posted, $transaction->status);
    }

    #[Test]
    public function the_period_lock_can_be_switched_off(): void
    {
        config(['naipay.ledger.enforce_period_lock' => false]);

        AccountingPeriod::forceCreate([
            'name' => 'March 2026',
            'starts_on' => '2026-03-01',
            'ends_on' => '2026-03-31',
            'status' => AccountingPeriodStatus::Closed,
        ]);

        $transaction = $this->ledger()->post(
            transactionType: 'test', description: 'Lock disabled.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100.00')),
            ],
            transactionDate: Carbon::parse('2026-03-15'),
        );

        $this->assertSame(JournalTransactionStatus::Posted, $transaction->status);
    }

    private function ledger(): LedgerPostingService
    {
        return app(LedgerPostingService::class);
    }

    /**
     * Runs a callback outside the test's wrapping transaction, so separate
     * connections can genuinely see each other's writes.
     */
    private function withoutTransaction(callable $callback): void
    {
        DB::rollBack();

        try {
            $callback();
        } finally {
            DB::table('journal_entries')->delete();
            DB::table('account_balances')->delete();
            DB::table('journal_transactions')->delete();
            DB::beginTransaction();
        }
    }
}
