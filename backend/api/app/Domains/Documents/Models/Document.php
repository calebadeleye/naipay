<?php

declare(strict_types=1);

namespace App\Domains\Documents\Models;

use App\Domains\Documents\Enums\DocumentVerificationStatus;
use App\Domains\Identity\Models\Staff;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An uploaded document.
 *
 * @property int $id
 * @property string $file_path
 * @property string $file_name
 * @property string $mime_type
 * @property int $file_size
 * @property DocumentVerificationStatus $verification_status
 * @property Carbon|null $expires_at
 * @property int $version
 * @property bool $is_current
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'documents';

    /**
     * File metadata is absent: it is derived from the uploaded bytes by
     * DocumentStorage, never from the request. A client-supplied mime type or
     * path is exactly what a malicious upload would tamper with.
     *
     * @var list<string>
     */
    protected $fillable = [
        'document_type_id',
        'document_number',
        'issued_at',
        'expires_at',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'verified_by');
    }

    /**
     * The version this one replaced.
     *
     * @return BelongsTo<Document, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * @param  Builder<Document>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /**
     * @param  Builder<Document>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->where('verification_status', DocumentVerificationStatus::Verified->value);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Days until expiry; negative once lapsed, null when the document does not
     * expire.
     */
    public function daysUntilExpiry(): ?int
    {
        return $this->expires_at?->diffInDays(now()->startOfDay(), absolute: false) === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->expires_at, absolute: false);
    }

    /**
     * Whether this document currently satisfies its requirement.
     *
     * An expired document does not, even though it was verified once: a lapsed
     * identity document is evidence of who someone was, not who they are.
     */
    public function satisfiesRequirement(): bool
    {
        return $this->is_current
            && $this->verification_status->satisfiesRequirement()
            && ! $this->isExpired();
    }

    public function humanFileSize(): string
    {
        $bytes = $this->file_size;

        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verification_status' => DocumentVerificationStatus::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'verified_at' => 'datetime',
            'is_current' => 'boolean',
            'version' => 'integer',
            'file_size' => 'integer',
        ];
    }
}
