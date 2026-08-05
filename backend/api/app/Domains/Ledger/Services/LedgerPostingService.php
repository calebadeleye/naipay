<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Data\JournalLine;
use App\Domains\Ledger\Enums\DebitCredit;
use App\Domains\Ledger\Enums\JournalTransactionStatus;
use App\Domains\Ledger\Models\AccountBalance;
use App\Domains\Ledger\Models\AccountingPeriod;
use App\Domains\Ledger\Models\JournalEntry;
use App\Domains\Ledger\Models\JournalTransaction;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\FinancialIntegrityException;
use App\Support\Money\Money;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of financial truth in Naipay.
 *
 * Every balance-changing event in the system — a disbursement, a repayment
 * allocation, a write-off — becomes a call to `post()`. Nothing updates a
 * loan's outstanding balance, a merchant account's balance, or any other
 * cached figure without a corresponding balanced entry landing here first, in
 * the same database transaction.
 *
 * The guarantees this class exists to hold:
 *
 *   - every posting balances to the kobo, checked before anything is written;
 *   - once posted, an entry is never edited or deleted — only reversed;
 *   - the same financial event can never be posted twice, when the caller
 *     supplies an idempotency key;
 *   - two postings that touch the same account concurrently serialise
 *     correctly, because the account's balance row is locked for the
 *     duration;
 *   - a posting into a closed accounting period is refused.
 */
final class LedgerPostingService
{
    private const MODULE = 'ledger';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Posts a balanced journal transaction.
     *
     * @param  array<int, JournalLine>  $lines
     */
    public function post(
        string $transactionType,
        string $description,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?Carbon $transactionDate = null,
        ?Carbon $postingDate = null,
        ?string $idempotencyKey = null,
        ?Staff $actor = null,
        ?Staff $approvedBy = null,
        ?int $reversalOfId = null,
    ): JournalTransaction {
        // Checked before anything else: a caller retrying an operation that
        // already succeeded should get the original result back, not an
        // error and not a second posting.
        if ($idempotencyKey !== null) {
            $existing = JournalTransaction::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $this->assertBalanced($lines);

        $transactionDate ??= Carbon::today();
        $postingDate ??= $transactionDate;

        $this->assertPeriodIsOpen($postingDate);

        return DB::transaction(function () use (
            $transactionType, $description, $lines, $sourceType, $sourceId,
            $transactionDate, $postingDate, $idempotencyKey, $actor, $approvedBy, $reversalOfId,
        ): JournalTransaction {
            $accountsByCode = $this->resolveAccounts($lines);
            $balances = $this->lockAccountBalances($accountsByCode);

            $transaction = new JournalTransaction([
                'transaction_type' => $transactionType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'transaction_date' => $transactionDate,
                'posting_date' => $postingDate,
                'idempotency_key' => $idempotencyKey,
                'reversal_of_id' => $reversalOfId,
            ]);

            $transaction->transaction_reference = $this->references->next('journal');
            $transaction->status = JournalTransactionStatus::Posted;
            $transaction->created_by = $actor?->getKey();
            $transaction->approved_by = $approvedBy?->getKey();
            $transaction->save();

            foreach ($lines as $line) {
                $account = $accountsByCode[$line->accountCode];

                JournalEntry::create([
                    'journal_transaction_id' => $transaction->getKey(),
                    'ledger_account_id' => $account->getKey(),
                    'debit_amount' => $line->side === DebitCredit::Debit ? $line->amount : Money::zero(),
                    'credit_amount' => $line->side === DebitCredit::Credit ? $line->amount : Money::zero(),
                    'description' => $line->description,
                    'merchant_id' => $line->merchantId,
                    'business_id' => $line->businessId,
                    'loan_id' => $line->loanId,
                    'branch_id' => $line->branchId,
                ]);

                $this->applyToBalance($balances[$account->getKey()], $line, $transaction);
            }

            $this->audit->record(
                action: 'ledger.posted',
                module: self::MODULE,
                subject: $transaction,
                newValues: [
                    'transaction_type' => $transactionType,
                    'lines' => array_map(
                        static fn (JournalLine $l): string => "{$l->side->value}:{$l->accountCode}:{$l->amount->toDecimalString()}",
                        $lines,
                    ),
                ],
                eventType: 'create',
                actor: $actor,
            );

            return $transaction->fresh('entries');
        });
    }

    /**
     * Reverses a posted transaction with a second, independent, balanced
     * posting — every line mirrored to the opposite side of the same account.
     *
     * The original is never edited beyond recording that it has been
     * reversed; both it and the reversal remain in the journal permanently.
     */
    public function reverse(JournalTransaction $original, string $reason, Staff $actor): JournalTransaction
    {
        if ($original->isReversed()) {
            throw new DomainException('This transaction has already been reversed.');
        }

        if ($original->isReversal()) {
            throw new DomainException(
                'A reversal cannot itself be reversed. Post a new correcting entry instead.',
            );
        }

        $original->loadMissing('entries.account');

        $mirroredLines = $original->entries->map(
            fn (JournalEntry $entry): JournalLine => $entry->isDebit()
                ? JournalLine::credit(
                    $entry->account->code,
                    $entry->debit_amount,
                    description: $entry->description,
                    merchantId: $entry->merchant_id,
                    businessId: $entry->business_id,
                    loanId: $entry->loan_id,
                    branchId: $entry->branch_id,
                )
                : JournalLine::debit(
                    $entry->account->code,
                    $entry->credit_amount,
                    description: $entry->description,
                    merchantId: $entry->merchant_id,
                    businessId: $entry->business_id,
                    loanId: $entry->loan_id,
                    branchId: $entry->branch_id,
                ),
        )->all();

        return DB::transaction(function () use ($original, $mirroredLines, $reason, $actor): JournalTransaction {
            $reversal = $this->post(
                transactionType: 'reversal',
                description: "Reversal of {$original->transaction_reference}: {$reason}",
                lines: $mirroredLines,
                sourceType: $original->source_type,
                sourceId: $original->source_id,
                idempotencyKey: "reversal:{$original->getKey()}",
                actor: $actor,
                reversalOfId: $original->getKey(),
            );

            $original->forceFill([
                'status' => JournalTransactionStatus::Reversed,
                'reversed_by_id' => $reversal->getKey(),
                'reversed_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'ledger.reversed',
                module: self::MODULE,
                subject: $original,
                reason: $reason,
                eventType: 'update',
                actor: $actor,
            );

            return $reversal;
        });
    }

    /**
     * @param  array<int, JournalLine>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new FinancialIntegrityException(
                'A journal transaction needs at least two lines to balance.',
            );
        }

        $currency = $lines[0]->amount->currency();

        $debits = Money::sum(
            array_map(
                static fn (JournalLine $l): Money => $l->side === DebitCredit::Debit ? $l->amount : Money::zero($currency),
                $lines,
            ),
            $currency,
        );

        $credits = Money::sum(
            array_map(
                static fn (JournalLine $l): Money => $l->side === DebitCredit::Credit ? $l->amount : Money::zero($currency),
                $lines,
            ),
            $currency,
        );

        if (! $debits->equals($credits)) {
            throw FinancialIntegrityException::unbalanced(
                $debits->toDecimalString(),
                $credits->toDecimalString(),
            );
        }
    }

    private function assertPeriodIsOpen(Carbon $postingDate): void
    {
        if (! (bool) config('naipay.ledger.enforce_period_lock', true)) {
            return;
        }

        // A posting date with no defined period is not constrained by one;
        // periods are opt-in bookkeeping, not a prerequisite for posting.
        $period = AccountingPeriod::query()->covering($postingDate)->first();

        if ($period !== null && $period->isClosed()) {
            throw new DomainException(
                "The accounting period covering {$postingDate->toDateString()} is closed. "
                .'This transaction cannot be posted into it.',
            );
        }
    }

    /**
     * @param  array<int, JournalLine>  $lines
     * @return Collection<string, LedgerAccount>
     */
    private function resolveAccounts(array $lines): Collection
    {
        $codes = collect($lines)->pluck('accountCode')->unique()->values();

        $accounts = LedgerAccount::query()->whereIn('code', $codes)->get()->keyBy('code');

        $missing = $codes->diff($accounts->keys());

        if ($missing->isNotEmpty()) {
            throw new DomainException(
                'Unknown ledger account code(s): '.$missing->implode(', '),
            );
        }

        $inactive = $accounts->filter(fn (LedgerAccount $a): bool => ! $a->isActive());

        if ($inactive->isNotEmpty()) {
            throw new DomainException(
                'Cannot post to an inactive ledger account: '.$inactive->pluck('code')->implode(', '),
            );
        }

        return $accounts;
    }

    /**
     * Locks every involved account's balance row for the duration of this
     * transaction, in a fixed order (ascending account id) regardless of the
     * order lines were supplied in.
     *
     * The ordering is what prevents deadlock: if one posting touches accounts
     * A and B while a concurrent posting touches B and A, locking in an
     * arbitrary per-call order would let each hold one lock while waiting for
     * the other. Locking both in the same global order means the second
     * transaction simply waits for the first to finish, every time.
     *
     * @param  Collection<string, LedgerAccount>  $accountsByCode
     * @return Collection<int, AccountBalance>
     */
    private function lockAccountBalances(Collection $accountsByCode): Collection
    {
        $accountIds = $accountsByCode->pluck('id')->unique()->sort()->values();

        // A brand-new account has no balance row yet; created ahead of the
        // lock so every account involved is guaranteed one to lock.
        foreach ($accountIds as $accountId) {
            AccountBalance::query()->firstOrCreate(['ledger_account_id' => $accountId]);
        }

        return AccountBalance::query()
            ->whereIn('ledger_account_id', $accountIds)
            ->orderBy('ledger_account_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('ledger_account_id');
    }

    private function applyToBalance(AccountBalance $balance, JournalLine $line, JournalTransaction $transaction): void
    {
        $newDebits = $balance->total_debits->plus($line->side === DebitCredit::Debit ? $line->amount : Money::zero());
        $newCredits = $balance->total_credits->plus($line->side === DebitCredit::Credit ? $line->amount : Money::zero());

        $balance->forceFill([
            'total_debits' => $newDebits,
            'total_credits' => $newCredits,
            'balance' => $newDebits->minus($newCredits),
            'last_journal_transaction_id' => $transaction->getKey(),
            'updated_at' => now(),
        ])->save();
    }
}
