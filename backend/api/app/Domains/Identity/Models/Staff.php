<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\AccessScope;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Enums\StaffStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * An internal staff account.
 *
 * The only authenticatable identity in the first release. Merchants will get
 * their own model and guard when the merchant portal is built, so a merchant
 * credential can never satisfy an administrative endpoint.
 *
 * @property int $id
 * @property string $staff_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string $email
 * @property string|null $username
 * @property string|null $phone
 * @property string|null $job_title
 * @property StaffStatus $status
 * @property bool $must_change_password
 * @property Carbon|null $password_changed_at
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 */
class Staff extends Authenticatable
{
    /** @use HasFactory<StaffFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $table = 'staff';

    /**
     * Mass assignment is restricted to fields an administrator legitimately
     * sets. Everything governing authentication state — password, lockout
     * counters, two-factor secrets — is assigned explicitly by the services
     * that own it, so no request payload can reach it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'staff_number',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'username',
        'phone',
        'job_title',
        'branch_id',
        'access_scope',
        'department',
        'status',
        'created_by',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    // --- Relationships -----------------------------------------------------

    /**
     * @return HasMany<LoginAttempt, $this>
     */
    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Branches this staff member manages. Usually none or one.
     *
     * @return HasMany<Branch, $this>
     */
    public function managedBranches(): HasMany
    {
        return $this->hasMany(Branch::class, 'manager_id');
    }

    // --- Organisational access ---------------------------------------------

    public function accessScope(): AccessScope
    {
        return $this->access_scope ?? AccessScope::Branch;
    }

    /**
     * Whether this staff member may see records belonging to a branch.
     *
     * A branch-scoped member without a branch assigned can see nothing, which
     * is the correct failure direction: an unassigned account should not
     * silently inherit organisation-wide reach.
     */
    public function canAccessBranch(?int $branchId): bool
    {
        if ($this->accessScope()->isGlobal()) {
            return true;
        }

        if ($branchId === null) {
            return false;
        }

        // Department scope crosses branches by design — a compliance officer
        // reviews KYC wherever it was captured.
        if ($this->accessScope() === AccessScope::Department) {
            return true;
        }

        return $this->branch_id === $branchId;
    }

    /**
     * How much this staff member may approve.
     *
     * Null means no approval authority, which is deliberately distinct from a
     * limit of zero — the latter is an explicit decision that someone holds the
     * role but currently approves nothing.
     */
    public function canApproveAmount(Money $amount): bool
    {
        $limit = $this->approval_limit;

        if ($limit === null) {
            return false;
        }

        return $limit->greaterThanOrEqualTo($amount);
    }

    public function hasApprovalAuthority(): bool
    {
        return $this->approval_limit !== null;
    }

    // --- Identity ----------------------------------------------------------

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }

    public function initials(): string
    {
        return mb_strtoupper(
            mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1)
        );
    }

    // --- Authentication state ----------------------------------------------

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

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether policy forbids this account from disabling two-factor.
     *
     * Driven by both the configured privileged roles and the permissions the
     * account actually holds, so a bespoke role carrying a privileged
     * permission is covered without anyone remembering to list it.
     */
    public function requiresTwoFactor(): bool
    {
        /** @var array<int, string> $mandatoryRoles */
        $mandatoryRoles = config('naipay.security.mandatory_two_factor_roles', []);

        if ($this->hasAnyRole($mandatoryRoles)) {
            return true;
        }

        foreach (Permission::privileged() as $permission) {
            if ($this->hasPermissionTo($permission->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the account must complete a blocking step before it can be
     * used normally. Only a forced password change qualifies: two-factor
     * enrolment is nudged, not enforced.
     */
    public function hasOutstandingSecuritySteps(): bool
    {
        return $this->must_change_password;
    }

    /**
     * True when the account's role mandates two-factor but it is not yet set
     * up — the console shows a persistent banner, but the account still works.
     */
    public function twoFactorSetupPending(): bool
    {
        return $this->requiresTwoFactor() && ! $this->hasTwoFactorEnabled();
    }

    // --- Authorisation -----------------------------------------------------

    public function isSuperAdministrator(): bool
    {
        return $this->hasRole(RoleEnum::SuperAdministrator->value);
    }

    /**
     * Every permission this account holds, flattened across its roles.
     *
     * @return Collection<int, string>
     */
    public function permissionNames(): Collection
    {
        return $this->getAllPermissions()->pluck('name')->values();
    }

    /**
     * @return Collection<int, string>
     */
    public function roleNames(): Collection
    {
        return $this->getRoleNames()->values();
    }

    // --- Credentials -------------------------------------------------------

    /**
     * Lets an operator sign in with either their email or their username.
     */
    public static function findForAuthentication(string $identifier): ?self
    {
        $identifier = trim($identifier);

        return self::query()
            ->where('email', mb_strtolower($identifier))
            ->orWhere('username', $identifier)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StaffStatus::class,
            'access_scope' => AccessScope::class,
            // Deliberately not mass-assignable: changing an approval limit is a
            // maker-checked operation set explicitly by the staff service.
            'approval_limit' => MoneyCast::class,
            'suspended_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            // Encrypted at rest: a database dump must not yield working
            // second factors.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
