<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record of one authentication attempt.
 *
 * Append-only. Nothing in the application updates or deletes these rows: they
 * are the evidence behind account lockout and the login history an operator
 * reviews for access they do not recognise.
 *
 * @property int $id
 * @property int|null $staff_id
 * @property string $identifier
 * @property bool $successful
 * @property string|null $failure_reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device_name
 * @property string|null $correlation_id
 * @property Carbon $attempted_at
 */
class LoginAttempt extends Model
{
    public const FAILURE_INVALID_CREDENTIALS = 'invalid_credentials';

    public const FAILURE_ACCOUNT_LOCKED = 'account_locked';

    public const FAILURE_ACCOUNT_INACTIVE = 'account_inactive';

    public const FAILURE_INVALID_TWO_FACTOR = 'invalid_two_factor';

    public const FAILURE_EXPIRED_CHALLENGE = 'expired_challenge';

    /**
     * These rows are written once, at the moment of the attempt, and never
     * carry an updated_at.
     */
    public $timestamps = false;

    protected $table = 'login_attempts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'staff_id',
        'identifier',
        'successful',
        'failure_reason',
        'ip_address',
        'user_agent',
        'device_name',
        'correlation_id',
        'attempted_at',
    ];

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }
}
