<?php

declare(strict_types=1);

namespace App\Domains\Investors\Models;

use App\Domains\Identity\Models\Staff;
use App\Domains\Investors\Enums\InvestorStatus;
use Database\Factories\InvestorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * An external investor: someone who has put capital into the business and
 * signs in only to view the read-only dashboard. Authenticates through the
 * `investor` guard, entirely separate from Staff — see config/auth.php.
 *
 * @property int $id
 * @property string $investor_number
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property InvestorStatus $status
 * @property string|null $suspension_reason
 * @property Carbon|null $suspended_at
 * @property int|null $suspended_by
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 */
class Investor extends Authenticatable
{
    /** @use HasFactory<InvestorFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'investors';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'investor_number',
        'name',
        'email',
        'phone',
        'status',
        'created_by',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function fullName(): string
    {
        return $this->name;
    }

    public function initials(): string
    {
        $parts = array_filter(explode(' ', trim($this->name)));
        $initials = array_map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)), $parts);

        return implode('', array_slice($initials, 0, 2));
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Whether this account may sign in right now, ignoring credentials.
     */
    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate() && ! $this->isLocked() && $this->deleted_at === null;
    }

    public static function findForAuthentication(string $email): ?self
    {
        return self::query()->where('email', mb_strtolower(trim($email)))->first();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'suspended_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvestorStatus::class,
            'password' => 'hashed',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
