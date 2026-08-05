<?php

declare(strict_types=1);

namespace App\Domains\Receipts\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Receipts\Http\Resources\ReceiptResource;
use App\Domains\Receipts\Models\Receipt;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReceiptController
{
    private const RELATIONS = ['repayment', 'loan', 'merchant', 'business'];

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['receipt_number'],
            filters: [
                'merchant_id' => FilterType::Exact,
                'loan_id' => FilterType::Exact,
                'created_at' => FilterType::DateRange,
            ],
            sortable: ['receipt_number', 'issued_at'],
            defaultSort: ['-issued_at'],
        );

        $query = Receipt::query()
            ->whereHas('loan', fn ($q) => $q->visibleTo($actor))
            ->with(['merchant', 'loan']);

        $receipts = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $receipts->through(fn (Receipt $receipt) => new ReceiptResource($receipt)),
            message: 'Receipts retrieved.',
        );
    }

    public function show(Receipt $receipt): JsonResponse
    {
        return ApiResponse::success(
            new ReceiptResource($receipt->load(self::RELATIONS)),
            'Receipt retrieved.',
        );
    }
}
