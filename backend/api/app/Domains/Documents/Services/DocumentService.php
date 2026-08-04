<?php

declare(strict_types=1);

namespace App\Domains\Documents\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Documents\Enums\DocumentVerificationStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Identity\Models\Staff;
use App\Support\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploading, verifying and superseding documents.
 *
 * Documents are evidence. Nothing here deletes one: replacing a document
 * supersedes the previous version, because the file compliance actually looked
 * at when they approved a merchant has to remain retrievable years later.
 */
final class DocumentService
{
    private const MODULE = 'documents';

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Attaches a document to a record.
     *
     * If a current document of the same type already exists it is superseded
     * rather than replaced, and the new one starts at the next version.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upload(
        Model $owner,
        DocumentType $type,
        UploadedFile $file,
        array $attributes,
        Staff $actor,
    ): Document {
        $this->assertTypeApplies($owner, $type);
        $this->assertRequiredMetadata($type, $attributes);

        // Stored before the transaction opens: writing to object storage can
        // be slow, and holding a database transaction across a network call to
        // S3 would keep row locks open for the duration.
        $stored = $this->storage->store($file, $this->directoryFor($owner));

        try {
            return DB::transaction(function () use ($owner, $type, $stored, $attributes, $actor): Document {
                $previous = $this->currentDocumentFor($owner, $type);

                $document = new Document($attributes);

                $document->documentable_type = $owner::class;
                $document->documentable_id = $owner->getKey();
                $document->document_type_id = $type->getKey();

                $document->forceFill([
                    'file_path' => $stored['path'],
                    'file_name' => $stored['name'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $stored['size'],
                    'checksum' => $stored['checksum'],
                    'verification_status' => DocumentVerificationStatus::Pending,
                    'version' => $previous === null ? 1 : $previous->version + 1,
                    'supersedes_id' => $previous?->getKey(),
                    'is_current' => true,
                    'uploaded_by' => $actor->getKey(),
                ])->save();

                if ($previous !== null) {
                    $previous->forceFill([
                        'is_current' => false,
                        'verification_status' => DocumentVerificationStatus::Superseded,
                    ])->save();
                }

                $this->audit->record(
                    action: $previous === null ? 'document.uploaded' : 'document.replaced',
                    module: self::MODULE,
                    subject: $document,
                    newValues: [
                        'document_type' => $type->name,
                        'file_name' => $stored['name'],
                        'version' => $document->version,
                        'owner_type' => class_basename($owner),
                        'owner_id' => $owner->getKey(),
                    ],
                    eventType: 'create',
                    actor: $actor,
                );

                return $document->fresh();
            });
        } catch (Throwable $e) {
            // The database write failed, so the file that was just stored is
            // orphaned. Removing it keeps the disk consistent with the records.
            $this->storage->delete($stored['path']);

            throw $e;
        }
    }

    public function verify(Document $document, Staff $actor): Document
    {
        $this->assertVerifiable($document);

        if ($document->isExpired()) {
            throw new DomainException(
                'This document has already expired and cannot be verified. Ask for a current one.',
            );
        }

        return DB::transaction(function () use ($document, $actor): Document {
            $before = $document->getAttributes();

            $document->forceFill([
                'verification_status' => DocumentVerificationStatus::Verified,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->audit->recordChange('document.verified', self::MODULE, $document, $before, actor: $actor);

            return $document->fresh();
        });
    }

    public function reject(Document $document, string $reason, Staff $actor): Document
    {
        $this->assertVerifiable($document);

        return DB::transaction(function () use ($document, $reason, $actor): Document {
            $before = $document->getAttributes();

            $document->forceFill([
                'verification_status' => DocumentVerificationStatus::Rejected,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->recordChange(
                'document.rejected',
                self::MODULE,
                $document,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $document->fresh();
        });
    }

    /**
     * Records that a document has been downloaded.
     *
     * Identity documents leaving the system is exactly the event a data
     * protection review asks about, so every retrieval is on the record.
     */
    public function recordDownload(Document $document, Staff $actor): void
    {
        $this->audit->record(
            action: 'document.downloaded',
            module: self::MODULE,
            subject: $document,
            newValues: ['file_name' => $document->file_name],
            eventType: 'read',
            actor: $actor,
        );
    }

    /**
     * Marks verified documents whose expiry has passed.
     *
     * Run on a schedule. A lapsed document silently continuing to satisfy a
     * KYC requirement is how a book ends up unverifiable at audit.
     *
     * @return int Number of documents expired.
     */
    public function expireLapsedDocuments(): int
    {
        $lapsed = Document::query()
            ->current()
            ->verified()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now()->toDateString())
            ->get();

        foreach ($lapsed as $document) {
            $document->forceFill([
                'verification_status' => DocumentVerificationStatus::Expired,
            ])->save();

            $this->audit->record(
                action: 'document.expired',
                module: self::MODULE,
                subject: $document,
                newValues: ['expires_at' => $document->expires_at?->toDateString()],
                eventType: 'update',
                // No actor: the scheduler did this, not a person.
                actor: null,
            );
        }

        return $lapsed->count();
    }

    /**
     * Documents approaching expiry, for reminders.
     *
     * @return Collection<int, Document>
     */
    public function documentsExpiringWithin(int $days): Collection
    {
        return Document::query()
            ->current()
            ->verified()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', now()->toDateString())
            ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString())
            ->with(['type', 'documentable'])
            ->get();
    }

    /**
     * Which required document types a record is still missing or has
     * unverified.
     *
     * @return array<int, array{type: DocumentType, status: string}>
     */
    public function outstandingRequirements(Model $owner, string $appliesTo): array
    {
        $required = DocumentType::query()
            ->active()
            ->where('applies_to', $appliesTo)
            ->where('is_required', true)
            ->orderBy('display_order')
            ->get();

        $held = Document::query()
            ->where('documentable_type', $owner::class)
            ->where('documentable_id', $owner->getKey())
            ->current()
            ->get()
            ->keyBy('document_type_id');

        $outstanding = [];

        foreach ($required as $type) {
            $document = $held->get($type->getKey());

            if ($document === null) {
                $outstanding[] = ['type' => $type, 'status' => 'missing'];

                continue;
            }

            if (! $document->satisfiesRequirement()) {
                $outstanding[] = [
                    'type' => $type,
                    'status' => $document->isExpired() ? 'expired' : $document->verification_status->value,
                ];
            }
        }

        return $outstanding;
    }

    private function currentDocumentFor(Model $owner, DocumentType $type): ?Document
    {
        return Document::query()
            ->where('documentable_type', $owner::class)
            ->where('documentable_id', $owner->getKey())
            ->where('document_type_id', $type->getKey())
            ->current()
            ->first();
    }

    private function assertTypeApplies(Model $owner, DocumentType $type): void
    {
        if (! $type->isActive()) {
            throw new DomainException(
                "The document type “{$type->name}” is no longer accepted.",
                ['document_type_id' => ['This document type has been withdrawn.']],
            );
        }

        $expected = Str::lower(class_basename($owner));

        if ($type->applies_to->value !== $expected) {
            throw new DomainException(
                "“{$type->name}” is a {$type->applies_to->label()} document and cannot be attached here.",
                ['document_type_id' => ['This document type does not apply to this record.']],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertRequiredMetadata(DocumentType $type, array $attributes): void
    {
        $errors = [];

        if ($type->has_expiry && empty($attributes['expires_at'])) {
            $errors['expires_at'] = ["“{$type->name}” carries an expiry date, which must be recorded."];
        }

        if ($type->requires_document_number && empty($attributes['document_number'])) {
            $errors['document_number'] = ["“{$type->name}” has a document number, which must be recorded."];
        }

        if ($errors !== []) {
            throw new DomainException('This document is missing required details.', $errors);
        }
    }

    private function assertVerifiable(Document $document): void
    {
        if (! $document->is_current) {
            throw new DomainException(
                'This version has been superseded. Verify the current version instead.',
            );
        }
    }

    /**
     * Files are grouped by owner so a merchant's documents sit together, which
     * matters for retention and for a subject access request.
     */
    private function directoryFor(Model $owner): string
    {
        return Str::plural(Str::snake(class_basename($owner))).'/'.$owner->getKey();
    }
}
