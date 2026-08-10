<?php

declare(strict_types=1);

namespace App\Domains\Documents\Http\Controllers\Merchant;

use App\Domains\Businesses\Models\Business;
use App\Domains\Documents\Enums\DocumentOwnerType;
use App\Domains\Documents\Http\Requests\UploadMerchantDocumentRequest;
use App\Domains\Documents\Http\Resources\DocumentResource;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Services\DocumentService;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A merchant uploading and listing their own documents through the
 * self-service portal.
 *
 * Only `merchant` and `business` owner types are reachable here — loan,
 * guarantor, collateral and repayment documents stay staff-initiated.
 * Verification, rejection and download all stay staff-only too: a
 * merchant-uploaded document lands `Pending` exactly like a staff-uploaded
 * one and is reviewed the same way.
 */
final class DocumentController
{
    public function __construct(
        private readonly DocumentService $documents,
    ) {}

    public function types(Request $request, string $owner): JsonResponse
    {
        $ownerType = DocumentOwnerType::tryFrom($owner);

        if ($ownerType === null || ! in_array($ownerType, [DocumentOwnerType::Merchant, DocumentOwnerType::Business], true)) {
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

    public function indexForSelf(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        return $this->listFor($request, $merchant, DocumentOwnerType::Merchant->value);
    }

    public function storeForSelf(UploadMerchantDocumentRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        return $this->upload($request, $merchant, $merchant);
    }

    public function indexForBusiness(Request $request, Business $business): JsonResponse
    {
        $this->authoriseOwnership($request, $business);

        return $this->listFor($request, $business, DocumentOwnerType::Business->value);
    }

    public function storeForBusiness(UploadMerchantDocumentRequest $request, Business $business): JsonResponse
    {
        $this->authoriseOwnership($request, $business);

        /** @var Merchant $merchant */
        $merchant = $request->user();

        return $this->upload($request, $business, $merchant);
    }

    private function listFor(Request $request, Model $owner, string $appliesTo): JsonResponse
    {
        $documents = Document::query()
            ->where('documentable_type', $owner::class)
            ->where('documentable_id', $owner->getKey())
            ->current()
            ->with(['type'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(
            DocumentResource::collection($documents)->resolve(),
            'Documents retrieved.',
        );
    }

    private function upload(UploadMerchantDocumentRequest $request, Model $owner, Merchant $actor): JsonResponse
    {
        $type = DocumentType::findOrFail($request->integer('document_type_id'));

        $document = $this->documents->upload(
            $owner,
            $type,
            $request->file('file'),
            $request->safe()->only(['document_number', 'issued_at', 'expires_at']),
            $actor,
        );

        return ApiResponse::created(
            new DocumentResource($document->load(['type', 'uploadedByMerchant'])),
            $document->version > 1
                ? "“{$type->name}” replaced. The previous version has been kept."
                : "“{$type->name}” uploaded.",
        );
    }

    private function authoriseOwnership(Request $request, Business $business): void
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        abort_unless(
            $business->merchant_id === $merchant->id,
            404,
            'The requested business was not found.',
        );
    }
}
