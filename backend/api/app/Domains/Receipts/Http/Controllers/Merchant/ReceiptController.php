<?php

declare(strict_types=1);

namespace App\Domains\Receipts\Http\Controllers\Merchant;

use App\Domains\Merchants\Models\Merchant;
use App\Domains\Receipts\Http\Resources\ReceiptResource;
use App\Domains\Receipts\Models\Receipt;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A merchant viewing their own repayment receipts through the self-service
 * portal — the "statements" a merchant asks about, since this system has no
 * per-merchant bank statement of its own; a Receipt is generated
 * automatically for every approved repayment.
 */
final class ReceiptController
{
    private const RELATIONS = ['repayment', 'loan', 'business'];

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['receipt_number'],
            filters: [
                'loan_id' => FilterType::Exact,
                'created_at' => FilterType::DateRange,
            ],
            sortable: ['receipt_number', 'issued_at'],
            defaultSort: ['-issued_at'],
        );

        $query = Receipt::query()->where('merchant_id', $merchant->id)->with(['repayment', 'loan']);

        $receipts = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $receipts->through(fn (Receipt $receipt) => new ReceiptResource($receipt)),
            message: 'Receipts retrieved.',
        );
    }

    public function show(Request $request, Receipt $receipt): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        abort_unless(
            $receipt->merchant_id === $merchant->id,
            404,
            'The requested receipt was not found.',
        );

        return ApiResponse::success(
            new ReceiptResource($receipt->load(self::RELATIONS)),
            'Receipt retrieved.',
        );
    }
}
