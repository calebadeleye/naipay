<?php

declare(strict_types=1);

namespace App\Domains\Documents\Models;

use App\Domains\Documents\Enums\DocumentOwnerType;
use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of document Naipay asks for.
 *
 * @property int $id
 * @property string $name
 * @property DocumentOwnerType $applies_to
 * @property bool $is_required
 * @property bool $has_expiry
 */
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    protected $table = 'document_types';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'applies_to',
        'category',
        'is_required',
        'has_expiry',
        'requires_document_number',
        'display_order',
        'status',
    ];

    /**
     * @param  Builder<DocumentType>  $query
     */
    public function scopeFor(Builder $query, DocumentOwnerType $owner): void
    {
        $query->where('applies_to', $owner->value);
    }

    /**
     * @param  Builder<DocumentType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applies_to' => DocumentOwnerType::class,
            'is_required' => 'boolean',
            'has_expiry' => 'boolean',
            'requires_document_number' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}
