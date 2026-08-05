<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\LoanProducts\Http\Requests\StoreLoanProductRequest;
use App\Domains\LoanProducts\Http\Requests\UpdateLoanProductRequest;
use App\Domains\LoanProducts\Http\Resources\LoanProductResource;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\LoanProducts\Services\LoanCalculator;
use App\Support\Exceptions\DomainException;
use App\Support\Http\ApiResponse;
use App\Support\Money\Money;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class LoanProductController
{
    private const MODULE = 'loan_products';

    public function __construct(
        private readonly LoanCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $specification = QuerySpecification::make(
            searchable: ['code', 'name', 'description'],
            filters: [
                'status' => FilterType::In,
                'repayment_frequency' => FilterType::In,
                'interest_method' => FilterType::In,
            ],
            sortable: ['code', 'name', 'interest_rate', 'display_order', 'created_at'],
            defaultSort: ['display_order', 'name'],
        );

        $products = QueryPipeline::for($request, $specification)->paginate(LoanProduct::query());

        return ApiResponse::paginated(
            $products->through(fn (LoanProduct $product) => new LoanProductResource($product)),
            message: 'Loan products retrieved.',
        );
    }

    /**
     * Active products, for the package picker on a loan application.
     */
    public function options(): JsonResponse
    {
        $products = LoanProduct::query()
            ->active()
            ->orderBy('display_order')
            ->get()
            ->map(fn (LoanProduct $product): array => [
                'value' => $product->id,
                'label' => $product->name,
                'code' => $product->code,
                'summary' => $product->summary(),
                'frequency' => $product->repayment_frequency->value,
                'minimum_amount' => $product->minimum_amount->toDecimalString(),
                'maximum_amount' => $product->maximum_amount->toDecimalString(),
                'minimum_tenor' => $product->minimum_tenor,
                'maximum_tenor' => $product->maximum_tenor,
                'default_tenor' => $product->default_tenor,
                'tenor_unit' => $product->tenor_unit->value,
            ]);

        return ApiResponse::success($products->all(), 'Loan product options retrieved.');
    }

    /**
     * Prices a hypothetical loan and returns the full schedule.
     *
     * Used by the officer's calculation preview before an application is
     * created, so the merchant is told exactly what they will repay and on
     * which dates before they agree to anything.
     */
    public function preview(Request $request, LoanProduct $product): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'tenor' => ['required', 'integer', 'min:1'],
            'disbursement_date' => ['nullable', 'date'],
            'first_repayment_date' => ['nullable', 'date', 'after_or_equal:disbursement_date'],
        ]);

        $amount = Money::fromDecimal($validated['amount']);

        if (! $product->acceptsAmount($amount)) {
            throw new DomainException(
                "{$product->name} lends between {$product->minimum_amount->format()} and {$product->maximum_amount->format()}.",
                ['amount' => ['The amount is outside this product’s limits.']],
            );
        }

        if (! $product->acceptsTenor((int) $validated['tenor'])) {
            $unit = mb_strtolower($product->tenor_unit->label());

            throw new DomainException(
                "{$product->name} runs for {$product->minimum_tenor} to {$product->maximum_tenor} {$unit}.",
                ['tenor' => ['The tenor is outside this product’s limits.']],
            );
        }

        $terms = $product->termsFor(
            $amount,
            (int) $validated['tenor'],
            isset($validated['disbursement_date'])
                ? Carbon::parse($validated['disbursement_date'])
                : Carbon::today(),
            isset($validated['first_repayment_date'])
                ? Carbon::parse($validated['first_repayment_date'])
                : null,
        );

        $fees = $product->upfrontFeesFor($amount);
        $schedule = $this->calculator->schedule($terms, $fees);

        return ApiResponse::success(
            [
                'summary' => [
                    'principal' => $schedule->principal->jsonSerialize(),
                    'total_interest' => $schedule->totalInterest->jsonSerialize(),
                    'total_fees' => $schedule->totalFees->jsonSerialize(),
                    'total_payable' => $schedule->totalPayable()->jsonSerialize(),
                    'instalment_count' => $schedule->instalmentCount(),
                    'first_repayment_date' => $schedule->firstRepaymentDate()->toDateString(),
                    'maturity_date' => $schedule->maturityDate()->toDateString(),
                    // Net of any upfront fee, so the officer can tell the
                    // merchant what actually reaches them.
                    'net_disbursement' => $schedule->principal->minus($fees)->jsonSerialize(),
                ],
                'schedule' => $schedule->toArray(),
            ],
            'Repayment schedule calculated.',
        );
    }

    public function store(StoreLoanProductRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $product = DB::transaction(function () use ($request, $actor): LoanProduct {
            $product = new LoanProduct($request->validated());
            $product->created_by = $actor->getKey();
            $product->save();

            $this->audit->recordCreation('loan_product.created', self::MODULE, $product, $actor);

            return $product->fresh();
        });

        return ApiResponse::created(
            new LoanProductResource($product),
            "Loan product {$product->code} created.",
        );
    }

    public function show(LoanProduct $product): JsonResponse
    {
        return ApiResponse::success(new LoanProductResource($product), 'Loan product retrieved.');
    }

    /**
     * Changing a product's pricing affects only loans booked from now on.
     * Existing loans carry the terms they were sold on, which live on the loan
     * itself rather than being read back from the product.
     */
    public function update(UpdateLoanProductRequest $request, LoanProduct $product): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = DB::transaction(function () use ($request, $product, $actor): LoanProduct {
            $before = $product->getAttributes();

            $product->fill($request->validated());
            $product->save();

            $this->audit->recordChange('loan_product.updated', self::MODULE, $product, $before, actor: $actor);

            return $product->fresh();
        });

        return ApiResponse::success(
            new LoanProductResource($updated),
            'Loan product updated. Existing loans are unaffected.',
        );
    }

    /**
     * Retires or reactivates a product.
     *
     * There is no delete: loans booked under a product must keep pointing at
     * the terms they were sold on.
     */
    public function changeStatus(Request $request, LoanProduct $product): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,retired'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        if ($product->status === $validated['status']) {
            throw new DomainException("This product is already {$validated['status']}.");
        }

        $updated = DB::transaction(function () use ($product, $validated, $actor): LoanProduct {
            $before = $product->getAttributes();

            $product->status = $validated['status'];
            $product->save();

            $this->audit->recordChange(
                "loan_product.{$validated['status']}",
                self::MODULE,
                $product,
                $before,
                reason: $validated['reason'],
                actor: $actor,
            );

            return $product->fresh();
        });

        return ApiResponse::success(
            new LoanProductResource($updated),
            "Loan product {$updated->code} is now {$updated->status}.",
        );
    }
}
