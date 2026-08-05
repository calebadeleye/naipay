<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Enums\JournalTransactionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One posted financial event.
 *
 * Immutable by construction, the same discipline as AuditLog: `update()` and
 * `delete()` are refused at the model, not left to callers to avoid. A trail
 * that can be quietly edited after the fact is not a ledger.
 *
 * @property int $id
 * @property string $transaction_reference
 * @property string $transaction_type
 * @property JournalTransactionStatus $status
 * @property Carbon $transaction_date
 * @property Carbon $posting_date
 */
class JournalTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'journal_transactions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'transaction_reference',
        'transaction_type',
        'source_type',
        'source_id',
        'description',
        'currency',
        'transaction_date',
        'posting_date',
        'status',
        'idempotency_key',
        'created_by',
        'approved_by',
        'reversal_of_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            // The one change this model permits after posting, made once by
            // LedgerPostingService::reverse() — marking that a reversal now
            // exists. Everything else about a posted transaction is
            // permanent.
            $onlyReversalLinkage = collect($transaction->getDirty())
                ->keys()
                ->every(fn (string $key): bool => in_array($key, ['status', 'reversed_by_id', 'reversed_at'], true));

            if (! $onlyReversalLinkage) {
                throw new RuntimeException(
                    'Journal transactions are immutable once posted. Post a reversal instead.',
                );
            }
        });

        static::deleting(function (): never {
            throw new RuntimeException('Journal transactions are permanent and cannot be deleted.');
        });
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * @return BelongsTo<JournalTransaction, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<JournalTransaction, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isReversed(): bool
    {
        return $this->status === JournalTransactionStatus::Reversed;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /**
     * @param  Builder<JournalTransaction>  $query
     */
    public function scopeForSource(Builder $query, string $type, int $id): void
    {
        $query->where('source_type', $type)->where('source_id', $id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JournalTransactionStatus::class,
            'transaction_date' => 'date',
            'posting_date' => 'date',
            'reversed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
