<?php

declare(strict_types=1);

namespace App\Domains\Audit\Models;

use App\Domains\Identity\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One entry in the audit trail.
 *
 * Immutable by construction: the model refuses updates and deletes outright
 * rather than relying on nobody calling them. An auditor's confidence in this
 * table is worth more than the convenience of being able to correct a typo in
 * it, and a correction would itself be an unrecorded change.
 *
 * @property int $id
 * @property int|null $staff_id
 * @property string|null $actor_name
 * @property string $action
 * @property string $module
 * @property string $event_type
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $reason
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'staff_id',
        'actor_name',
        'actor_roles',
        'action',
        'module',
        'event_type',
        'auditable_type',
        'auditable_id',
        'auditable_reference',
        'old_values',
        'new_values',
        'reason',
        'ip_address',
        'user_agent',
        'session_id',
        'correlation_id',
    ];

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The fields that changed, paired old and new, for the comparison view.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        $changes = [];

        foreach ($keys as $key) {
            $changes[$key] = [
                'old' => $old[$key] ?? null,
                'new' => $new[$key] ?? null,
            ];
        }

        return $changes;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Enforced at the model rather than left to discipline. A trail that
        // can be quietly edited is not a trail.
        static::updating(function (): never {
            throw new RuntimeException('Audit log entries are immutable and cannot be modified.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit log entries are permanent and cannot be deleted.');
        });
    }
}
