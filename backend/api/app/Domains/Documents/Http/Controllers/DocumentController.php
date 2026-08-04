<?php

declare(strict_types=1);

namespace App\Domains\Documents\Http\Controllers;

use App\Domains\Businesses\Models\Business;
use App\Domains\Documents\Enums\DocumentOwnerType;
use App\Domains\Documents\Http\Requests\UploadDocumentRequest;
use App\Domains\Documents\Http\Resources\DocumentResource;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Services\DocumentService;
use App\Domains\Documents\Services\DocumentStorage;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents attached to merchants and businesses.
 *
 * Files are never publicly addressable. Every retrieval passes through the
 * permission check and the branch scope, and is written to the audit trail —
 * identity documents leaving the system is exactly the event a data protection
 * review asks about.
 */
final class DocumentController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentStorage $storage,
    ) {}

    /**
     * Document types applicable to a kind of record, for the upload picker.
     */
    public function types(Request $request, string $owner): JsonResponse
    {
        $ownerType = DocumentOwnerType::tryFrom($owner);

        if ($ownerType === null) {
            return ApiResponse::notFound('Unknown document owner type.');
        }

        $types = DocumentType::query()
            ->active()
            ->for($ownerType)
            ->orderBy('display_order')
            ->get()
            ->map(fn (DocumentType $type): array => [
                'value' => $type->id,
                'label' => $type->name,
                'category' => $type->category,
                'is_required' => $type->is_required,
                'has_expiry' => $type->has_expiry,
                'requires_document_number' => $type->requires_document_number,
            ]);

        return ApiResponse::success($types->all(), 'Document types retrieved.');
    }

    public function indexForMerchant(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseAccess($request, $merchant->branch_id);

        return $this->listFor($request, $merchant, DocumentOwnerType::Merchant->value);
    }

    public function indexForBusiness(Request $request, Business $business): JsonResponse
    {
        $this->authoriseAccess($request, $business->merchant->branch_id);

        return $this->listFor($request, $business, DocumentOwnerType::Business->value);
    }

    public function storeForMerchant(UploadDocumentRequest $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseAccess($request, $merchant->branch_id);

        return $this->upload($request, $merchant);
    }

    public function storeForBusiness(UploadDocumentRequest $request, Business $business): JsonResponse
    {
        $this->authoriseAccess($request, $business->merchant->branch_id);

        return $this->upload($request, $business);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authoriseDocument($request, $document);

        return ApiResponse::success(
            new DocumentResource($document->load(['type', 'uploadedBy', 'verifiedBy'])),
            'Document retrieved.',
        );
    }

    /**
     * Streams the file through the application. There is no public URL.
     */
    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authoriseDocument($request, $document);

        /** @var Staff $actor */
        $actor = $request->user();

        $this->documents->recordDownload($document, $actor);

        return $this->storage->stream($document->file_path);
    }

    public function verify(Request $request, Document $document): JsonResponse
    {
        $this->authoriseDocument($request, $document);

        /** @var Staff $actor */
        $actor = $request->user();

        $verified = $this->documents->verify($document, $actor);

        return ApiResponse::success(
            new DocumentResource($verified->load(['type', 'verifiedBy'])),
            'Document verified.',
        );
    }

    public function reject(Request $request, Document $document): JsonResponse
    {
        $this->authoriseDocument($request, $document);

        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $rejected = $this->documents->reject($document, $validated['reason'], $actor);

        return ApiResponse::success(
            new DocumentResource($rejected->load(['type', 'verifiedBy'])),
            'Document rejected.',
        );
    }

    /**
     * Which required documents a merchant still owes.
     */
    public function outstandingForMerchant(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseAccess($request, $merchant->branch_id);

        $outstanding = array_map(
            static fn (array $item): array => [
                'document_type_id' => $item['type']->id,
                'name' => $item['type']->name,
                'status' => $item['status'],
            ],
            $this->documents->outstandingRequirements($merchant, DocumentOwnerType::Merchant->value),
        );

        return ApiResponse::success(
            [
                'outstanding' => $outstanding,
                'is_complete' => $outstanding === [],
            ],
            'Outstanding document requirements retrieved.',
        );
    }

    private function listFor(Request $request, Model $owner, string $appliesTo): JsonResponse
    {
        $query = Document::query()
            ->where('documentable_type', $owner::class)
            ->where('documentable_id', $owner->getKey())
            ->with(['type', 'uploadedBy', 'verifiedBy'])
            ->orderByDesc('created_at');

        // Superseded versions are hidden by default: the current file is what
        // an operator needs, and the history is available on request.
        if (! $request->boolean('include_superseded')) {
            $query->current();
        }

        $documents = $query->get();

        return ApiResponse::success(
            DocumentResource::collection($documents)->resolve(),
            'Documents retrieved.',
        );
    }

    private function upload(UploadDocumentRequest $request, Model $owner): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $type = DocumentType::findOrFail($request->integer('document_type_id'));

        $document = $this->documents->upload(
            $owner,
            $type,
            $request->file('file'),
            $request->safe()->only(['document_number', 'issued_at', 'expires_at']),
            $actor,
        );

        return ApiResponse::created(
            new DocumentResource($document->load(['type', 'uploadedBy'])),
            $document->version > 1
                ? "“{$type->name}” replaced. The previous version has been kept."
                : "“{$type->name}” uploaded.",
        );
    }

    /**
     * Resolves the branch a document ultimately belongs to and checks access.
     *
     * Route model binding resolves a document by id regardless of who it
     * belongs to, so this has to run on every single-document action.
     */
    private function authoriseDocument(Request $request, Document $document): void
    {
        $owner = $document->documentable;

        $branchId = match (true) {
            $owner instanceof Merchant => $owner->branch_id,
            $owner instanceof Business => $owner->merchant?->branch_id,
            default => null,
        };

        $this->authoriseAccess($request, $branchId);
    }

    private function authoriseAccess(Request $request, ?int $branchId): void
    {
        /** @var Staff $actor */
        $actor = $request->user();

        abort_unless(
            $actor->canAccessBranch($branchId),
            404,
            'The requested document was not found.',
        );
    }
}
