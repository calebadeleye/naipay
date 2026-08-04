<?php

declare(strict_types=1);

namespace App\Domains\Branches\Models;

use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Identity\Models\Staff;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A Naipay branch.
 *
 * @property int $id
 * @property string $branch_code
 * @property string $name
 * @property string|null $city
 * @property string|null $state
 * @property BranchStatus $status
 * @property int|null $manager_id
 * @property Carbon|null $opened_at
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'branches';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_code',
        'name',
        'address',
        'city',
        'state',
        'country',
        'phone',
        'email',
        'manager_id',
        'status',
        'opened_at',
        'created_by',
    ];

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'manager_id');
    }

    /**
     * @return HasMany<Staff, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class, 'branch_id');
    }

    /**
     * @param  Builder<Branch>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', BranchStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatus::Active;
    }

    /**
     * Display label used in dropdowns and on records.
     */
    public function label(): string
    {
        return "{$this->name} ({$this->branch_code})";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'opened_at' => 'date',
            'closed_at' => 'date',
        ];
    }
}
