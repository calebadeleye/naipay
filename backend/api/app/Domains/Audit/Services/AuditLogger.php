<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Correlation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit trail.
 *
 * Every sensitive action routes through here, so the actor, the change, the
 * reason and the request context are captured the same way each time rather
 * than depending on each call site remembering the full set. The actor is
 * either a Staff member or, since the merchant self-service portal, a
 * Merchant acting on their own record — never both, see AuditLog.
 *
 * Values are scrubbed before they are written: an audit entry recording that a
 * merchant's BVN changed must record *that* it changed, never the value.
 */
final class AuditLogger
{
    /**
     * Records an action against a model.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        string $action,
        string $module,
        ?Model $subject = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $reason = null,
        string $eventType = 'update',
        Staff|Merchant|null $actor = null,
    ): AuditLog {
        $actor ??= $this->currentActor();
        $request = request();

        return AuditLog::create([
            'staff_id' => $actor instanceof Staff ? $actor->getKey() : null,
            'merchant_id' => $actor instanceof Merchant ? $actor->getKey() : null,
            'actor_type' => match (true) {
                $actor instanceof Staff => 'staff',
                $actor instanceof Merchant => 'merchant',
                default => null,
            },
            'actor_name' => $actor?->fullName(),
            // Denormalised so the entry still explains what authority the actor
            // held at the time, even after their roles change. Meaningless for
            // a merchant actor, who holds no roles.
            'actor_roles' => $actor instanceof Staff
                ? mb_substr($actor->roleNames()->implode(', '), 0, 400)
                : null,

            'action' => $action,
            'module' => $module,
            'event_type' => $eventType,

            'auditable_type' => $subject !== null ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'auditable_reference' => $this->referenceFor($subject),

            'old_values' => $this->scrub($oldValues) ?: null,
            'new_values' => $this->scrub($newValues) ?: null,

            'reason' => $reason,

            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 1000) : null,
            'session_id' => null,
            'correlation_id' => Correlation::id(),
        ]);
    }

    /**
     * Records a change to a model, capturing only the attributes that actually
     * differ.
     *
     * An entry listing forty unchanged fields buries the one that moved.
     */
    public function recordChange(
        string $action,
        string $module,
        Model $subject,
        array $before,
        ?string $reason = null,
        Staff|Merchant|null $actor = null,
    ): AuditLog {
        $after = $subject->getAttributes();

        $changedKeys = array_keys(array_filter(
            $after,
            static fn (mixed $value, string $key): bool => ! array_key_exists($key, $before)
                || $before[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        // Timestamps move on every save and say nothing about intent.
        $changedKeys = array_values(array_diff($changedKeys, ['updated_at', 'created_at']));

        $oldValues = [];
        $newValues = [];

        foreach ($changedKeys as $key) {
            $oldValues[$key] = $before[$key] ?? null;
            $newValues[$key] = $after[$key] ?? null;
        }

        return $this->record(
            action: $action,
            module: $module,
            subject: $subject,
            oldValues: $oldValues,
            newValues: $newValues,
            reason: $reason,
            eventType: 'update',
            actor: $actor,
        );
    }

    public function recordCreation(
        string $action,
        string $module,
        Model $subject,
        Staff|Merchant|null $actor = null,
    ): AuditLog {
        return $this->record(
            action: $action,
            module: $module,
            subject: $subject,
            newValues: $subject->getAttributes(),
            eventType: 'create',
            actor: $actor,
        );
    }

    private function currentActor(): ?Staff
    {
        // Guards may not be resolvable during console bootstrap or inside a
        // queued job, where there is legitimately no actor.
        if (! Auth::hasResolvedGuards()) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof Staff ? $user : null;
    }

    /**
     * Replaces sensitive values with a marker, keeping the fact of the change
     * without the value.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scrub(array $values): array
    {
        /** @var array<int, string> $sensitive */
        $sensitive = config('naipay.security.redacted_keys', []);

        $scrubbed = [];

        foreach ($values as $key => $value) {
            $normalised = mb_strtolower((string) $key);

            $isSensitive = false;

            foreach ($sensitive as $needle) {
                if (str_contains($normalised, mb_strtolower($needle))) {
                    $isSensitive = true;

                    break;
                }
            }

            // `[changed]` rather than the value: an auditor needs to know a BVN
            // was edited and by whom, never what it was changed to.
            $scrubbed[$key] = $isSensitive ? '[changed]' : $value;
        }

        return $scrubbed;
    }

    /**
     * Pulls the human-readable reference off a model where it has one.
     */
    private function referenceFor(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        // Presence is checked against the loaded attributes rather than read
        // speculatively: strict mode refuses a getAttribute() for a column the
        // model does not have, and this method is deliberately generic across
        // every auditable type.
        $attributes = $subject->getAttributes();

        foreach ([
            'staff_number',
            'branch_code',
            'merchant_number',
            'business_number',
            'loan_number',
            'repayment_number',
            'application_number',
            'receipt_number',
            'transaction_reference',
        ] as $attribute) {
            $value = $attributes[$attribute] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
