<?php

declare(strict_types=1);

namespace App\Domains\Documents\Http\Resources;

use App\Domains\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document as returned by the API.
 *
 * Never carries a file URL. The disk is private and files are streamed through
 * an authorised, audited endpoint — a signed or public URL could be forwarded
 * to anyone, and identity documents must not be shareable by link.
 *
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Document $document */
        $document = $this->resource;

        return [
            'id' => $document->id,

            'type' => $document->relationLoaded('type') && $document->type !== null ? [
                'id' => $document->type->id,
                'name' => $document->type->name,
                'category' => $document->type->category,
                'is_required' => $document->type->is_required,
            ] : null,

            'file_name' => $document->file_name,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'file_size_human' => $document->humanFileSize(),

            'document_number' => $document->document_number,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'is_expired' => $document->isExpired(),
            'days_until_expiry' => $document->daysUntilExpiry(),

            'verification_status' => $document->verification_status->value,
            'verification_status_label' => $document->verification_status->label(),
            'satisfies_requirement' => $document->satisfiesRequirement(),
            'rejection_reason' => $document->rejection_reason,

            'version' => $document->version,
            'is_current' => $document->is_current,
            'supersedes_id' => $document->supersedes_id,

            'uploaded_by' => $document->relationLoaded('uploadedBy') && $document->uploadedBy !== null
                ? $document->uploadedBy->fullName()
                : null,
            'uploaded_by_merchant' => $document->relationLoaded('uploadedByMerchant') && $document->uploadedByMerchant !== null
                ? $document->uploadedByMerchant->fullName()
                : null,
            'verified_by' => $document->relationLoaded('verifiedBy') && $document->verifiedBy !== null
                ? $document->verifiedBy->fullName()
                : null,
            'verified_at' => $document->verified_at?->toIso8601String(),

            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
