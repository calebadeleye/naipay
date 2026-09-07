<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Models;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Enums\BankAccountStatus;
use App\Domains\Identity\Models\Staff;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * One of Naipay's own designated bank accounts.
 *
 * @property int $id
 * @property string $bank_name
 * @property string $account_number
 * @property Collection<int, BankAccountPurpose> $purposes
 * @property BankAccountStatus $status
 * @property bool $is_default_collection_account
 * @property bool $is_default_disbursement_account
 */
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'bank_accounts';

    /**
     * The default flags and status are absent: they are maintained explicitly
     * by BankAccountService, which enforces "at most one default per
     * purpose" — a rule a plain mass assignment could silently break.
     *
     * @var list<string>
     */
    protected $fillable = [
        'bank_name',
        'bank_code',
        'account_name',
        'account_number',
        'branch_name',
        'currency',
        'purposes',
    ];

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }

    /**
     * @param  Builder<BankAccount>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', BankAccountStatus::Active->value);
    }

    /**
     * Accounts that carry the given purpose among possibly several.
     *
     * @param  Builder<BankAccount>  $query
     */
    public function scopeForPurpose(Builder $query, BankAccountPurpose $purpose): void
    {
        $query->whereJsonContains('purposes', $purpose->value);
    }

    public function hasPurpose(BankAccountPurpose $purpose): bool
    {
        return $this->purposes->contains($purpose);
    }

    /**
     * @return list<string>
     */
    public function purposeValues(): array
    {
        return $this->purposes->map(fn (BankAccountPurpose $purpose): string => $purpose->value)->values()->all();
    }

    /**
     * @return list<string>
     */
    public function purposeLabels(): array
    {
        return BankAccountPurpose::labelsFor($this->purposes);
    }

    /**
     * Whether this account may actually be used — as a disbursement source,
     * a collection destination, or a default.
     *
     * Requires both an active status and a completed approval. All
     * bank-account changes must be approved, per the brief; a newly created or
     * newly edited account is not usable until a different officer confirms
     * it.
     */
    public function canTransact(): bool
    {
        return $this->status->canTransact() && $this->isApproved();
    }

    public function isApproved(): bool
    {
        return $this->approved_by !== null;
    }

    /**
     * Grouped for display: 0123 456 789. The stored value stays the bare ten
     * digits, so a copy-and-paste still matches.
     */
    public function formattedAccountNumber(): string
    {
        return trim(chunk_split($this->account_number, 4, ' '));
    }

    public function label(): string
    {
        return "{$this->bank_name} — {$this->account_number} ({$this->account_name})";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purposes' => AsEnumCollection::of(BankAccountPurpose::class),
            'status' => BankAccountStatus::class,
            'is_default_collection_account' => 'boolean',
            'is_default_disbursement_account' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }
}
