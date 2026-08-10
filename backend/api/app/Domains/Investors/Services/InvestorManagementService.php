<?php

declare(strict_types=1);

namespace App\Domains\Investors\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\Investors\Enums\InvestorStatus;
use App\Domains\Investors\Models\Investor;
use App\Support\Exceptions\DomainException;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Investor lifecycle: creation, profile updates, suspension and reinstatement.
 *
 * Deliberately narrower than StaffManagementService — an investor holds no
 * roles or approval limits, and is never branch-scoped. It signs in only to
 * view a read-only dashboard, so the only access decisions here are whether
 * the account may sign in at all.
 */
final class InvestorManagementService
{
    private const MODULE = 'investors';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
        private readonly InvestorAuthenticationService $authentication,
    ) {}

    /**
     * Creates an investor account with a generated temporary password.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{investor: Investor, temporary_password: string}
     */
    public function create(array $attributes, Staff $actor): array
    {
        // Generated rather than chosen by the creating administrator, for the
        // same reason as staff: a password someone else picked is a password
        // someone else knows.
        $temporaryPassword = Str::random(16).'aA1!';

        $investor = DB::transaction(function () use ($attributes, $actor, $temporaryPassword): Investor {
            $investor = new Investor($attributes);
            $investor->investor_number = $this->references->next('investor');
            $investor->created_by = $actor->getKey();
            $investor->status = InvestorStatus::Active;

            $investor->forceFill(['password' => $temporaryPassword])->save();

            $this->audit->recordCreation('investor.created', self::MODULE, $investor, $actor);

            return $investor;
        });

        return [
            'investor' => $investor->fresh(),
            'temporary_password' => $temporaryPassword,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Investor $investor, array $attributes, Staff $actor): Investor
    {
        return DB::transaction(function () use ($investor, $attributes, $actor): Investor {
            $before = $investor->getAttributes();

            $investor->fill($attributes);
            $investor->save();

            $this->audit->recordChange('investor.updated', self::MODULE, $investor, $before, actor: $actor);

            return $investor->fresh();
        });
    }

    /**
     * Suspends an account and cuts every session immediately.
     */
    public function suspend(Investor $investor, string $reason, Staff $actor): Investor
    {
        if ($investor->status === InvestorStatus::Suspended) {
            throw new DomainException('This investor is already suspended.');
        }

        return DB::transaction(function () use ($investor, $reason, $actor): Investor {
            $before = $investor->getAttributes();

            $investor->forceFill([
                'status' => InvestorStatus::Suspended,
                'suspension_reason' => $reason,
                'suspended_at' => now(),
                'suspended_by' => $actor->getKey(),
            ])->save();

            // Status alone would only stop the next sign-in; an existing
            // bearer token would keep working until it expired.
            $this->authentication->signOutAllSessions($investor);

            $this->audit->recordChange(
                'investor.suspended',
                self::MODULE,
                $investor,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $investor->fresh();
        });
    }

    public function reinstate(Investor $investor, string $reason, Staff $actor): Investor
    {
        if ($investor->status !== InvestorStatus::Suspended) {
            throw new DomainException('Only a suspended investor can be reinstated.');
        }

        return DB::transaction(function () use ($investor, $reason, $actor): Investor {
            $before = $investor->getAttributes();

            $investor->forceFill([
                'status' => InvestorStatus::Active,
                'suspension_reason' => null,
                'suspended_at' => null,
                'suspended_by' => null,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $this->audit->recordChange(
                'investor.reinstated',
                self::MODULE,
                $investor,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $investor->fresh();
        });
    }
}
