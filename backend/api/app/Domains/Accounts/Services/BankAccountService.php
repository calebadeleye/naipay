<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Approvals\BankAccountApproval;
use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Enums\BankAccountStatus;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Approvals\Services\MakerCheckerGuard;
use App\Domains\Identity\Models\Staff;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Maintains Naipay's designated bank accounts.
 *
 * Every account here is a point real money moves through — the source a loan
 * is disbursed from, the destination a repayment instruction names — so every
 * change is maker-checked and every change is audited, without exception.
 */
final class BankAccountService
{
    private const MODULE = 'bank_accounts';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MakerCheckerGuard $makerChecker,
    ) {}

    /**
     * Registers a new designated account.
     *
     * Created in Active status but not yet usable: `canTransact()` also
     * requires approval, and nothing may disburse from or collect into this
     * account until a different officer confirms it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Staff $actor): BankAccount
    {
        return DB::transaction(function () use ($attributes, $actor): BankAccount {
            $account = new BankAccount($attributes);
            $account->status = BankAccountStatus::Active;
            $account->created_by = $actor->getKey();
            $account->save();

            $this->audit->recordCreation('bank_account.created', self::MODULE, $account, $actor);

            return $account->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(BankAccount $account, array $attributes, Staff $actor): BankAccount
    {
        return DB::transaction(function () use ($account, $attributes, $actor): BankAccount {
            $before = $account->getAttributes();

            // Changing where money actually flows — the bank, the account
            // number, or what the account is used for — is exactly what
            // approval exists to catch, so it is withdrawn and must be given
            // again. Correcting a display label does not.
            $materialFields = ['bank_name', 'account_number', 'account_purpose', 'currency'];
            $isMaterial = ! empty(array_intersect_key($attributes, array_flip($materialFields)))
                && collect($materialFields)->contains(
                    fn (string $field): bool => array_key_exists($field, $attributes)
                        && (string) $attributes[$field] !== (string) $account->{$field}
                );

            $account->fill($attributes);

            if ($isMaterial) {
                $account->approved_by = null;
                $account->approved_at = null;
            }

            $account->save();

            $this->audit->recordChange('bank_account.updated', self::MODULE, $account, $before, actor: $actor);

            return $account->fresh();
        });
    }

    /**
     * Confirms a proposed account or a material change to one, making it
     * usable. The officer who created it, or who last changed its material
     * details, cannot be the one who confirms it.
     */
    public function approve(BankAccount $account, Staff $actor): BankAccount
    {
        if ($account->isApproved()) {
            throw new DomainException('This account is already approved.');
        }

        $this->makerChecker->assertCanApprove($actor, new BankAccountApproval($account));

        return DB::transaction(function () use ($account, $actor): BankAccount {
            $before = $account->getAttributes();

            $account->forceFill([
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit->recordChange('bank_account.approved', self::MODULE, $account, $before, actor: $actor);

            return $account->fresh();
        });
    }

    /**
     * Makes an account the default for its purpose, unsetting whichever
     * account previously held that title.
     *
     * "Default collection account" and "default disbursement account" are
     * two independent flags: an account can hold either, both, or neither, and
     * setting one never disturbs the other.
     */
    public function setAsDefault(BankAccount $account, string $which, Staff $actor): BankAccount
    {
        $column = match ($which) {
            'collection' => 'is_default_collection_account',
            'disbursement' => 'is_default_disbursement_account',
            default => throw new DomainException("Unknown default type [{$which}]."),
        };

        if (! $account->status->canTransact()) {
            throw new DomainException(
                "{$account->label()} is {$account->status->label()} and cannot be made a default account.",
            );
        }

        return DB::transaction(function () use ($account, $column, $which, $actor): BankAccount {
            // Row-locked so two concurrent "make me the default" requests
            // cannot both succeed and leave two accounts holding the title.
            BankAccount::query()
                ->where($column, true)
                ->lockForUpdate()
                ->get()
                ->each(fn (BankAccount $previous) => $previous->forceFill([$column => false])->save());

            $before = $account->getAttributes();

            $account->forceFill([$column => true])->save();

            $this->audit->recordChange(
                'bank_account.default_changed',
                self::MODULE,
                $account,
                $before,
                reason: "Set as the default {$which} account.",
                actor: $actor,
            );

            return $account->fresh();
        });
    }

    public function changeStatus(BankAccount $account, BankAccountStatus $status, string $reason, Staff $actor): BankAccount
    {
        if ($account->status === $status) {
            throw new DomainException("This account is already {$status->label()}.");
        }

        return DB::transaction(function () use ($account, $status, $reason, $actor): BankAccount {
            $before = $account->getAttributes();

            $account->status = $status;

            // A closed or suspended account cannot remain anyone's default —
            // leaving the flag set would point disbursement at an account
            // that can no longer transact.
            if (! $status->canTransact()) {
                $account->is_default_collection_account = false;
                $account->is_default_disbursement_account = false;
            }

            $account->save();

            $this->audit->recordChange(
                "bank_account.{$status->value}",
                self::MODULE,
                $account,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $account->fresh();
        });
    }

    /**
     * The account a flow should use when the caller has not named one
     * explicitly — the configured default for that purpose.
     */
    public function defaultFor(BankAccountPurpose $purpose): ?BankAccount
    {
        $column = match ($purpose) {
            BankAccountPurpose::LoanRepaymentCollection => 'is_default_collection_account',
            BankAccountPurpose::LoanDisbursement => 'is_default_disbursement_account',
            default => null,
        };

        if ($column === null) {
            return BankAccount::query()->active()->forPurpose($purpose)->first();
        }

        return BankAccount::query()->active()->where($column, true)->first();
    }
}
