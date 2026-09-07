<?php

declare(strict_types=1);

namespace App\Domains\Reports\Data;

use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Reports\Support\PortfolioPeriod;
use Illuminate\Http\Request;

/**
 * The full set of constraints a portfolio dashboard request carries.
 *
 * One object, passed to every section of LoanPortfolioAnalyticsService, so a
 * filter can never be honoured by one panel and silently ignored by another.
 * All identifiers are already validated by PortfolioAnalyticsRequest; this is
 * a plain typed carrier.
 *
 * `repaymentStatus` is a derived loan-level classification, not the
 * RepaymentStatus enum: 'current' means no instalment is overdue as at the
 * period end, 'overdue' means at least one is.
 */
final class PortfolioFilter
{
    /**
     * @param  'current'|'overdue'|null  $repaymentStatus
     */
    public function __construct(
        public readonly PortfolioPeriod $period,
        public readonly ?int $loanProductId = null,
        public readonly ?LoanStatus $status = null,
        public readonly ?int $officerId = null,
        public readonly ?int $branchId = null,
        public readonly ?int $borrowerId = null,
        public readonly ?int $loanId = null,
        public readonly ?int $disbursementBankAccountId = null,
        public readonly ?int $minDaysPastDue = null,
        public readonly ?string $repaymentStatus = null,
        public readonly ?string $currency = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $status = $request->filled('status')
            ? LoanStatus::from((string) $request->string('status'))
            : null;

        $repaymentStatus = $request->filled('repayment_status')
            ? (string) $request->string('repayment_status')
            : null;

        return new self(
            period: PortfolioPeriod::resolve(
                $request->filled('range') ? (string) $request->string('range') : null,
                $request->filled('date_from') ? (string) $request->string('date_from') : null,
                $request->filled('date_to') ? (string) $request->string('date_to') : null,
            ),
            loanProductId: self::intOrNull($request, 'loan_product_id'),
            status: $status,
            officerId: self::intOrNull($request, 'loan_officer_id'),
            branchId: self::intOrNull($request, 'branch_id'),
            borrowerId: self::intOrNull($request, 'borrower_id'),
            loanId: self::intOrNull($request, 'loan_id'),
            disbursementBankAccountId: self::intOrNull($request, 'disbursement_channel_id'),
            minDaysPastDue: self::intOrNull($request, 'min_days_past_due'),
            repaymentStatus: in_array($repaymentStatus, ['current', 'overdue'], true) ? $repaymentStatus : null,
            currency: $request->filled('currency') ? (string) $request->string('currency') : null,
        );
    }

    /**
     * The filter as it was resolved, for the API to echo back so the client can
     * render active-filter chips and rebuild a shareable URL.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'range' => $this->period->key,
            'range_label' => $this->period->label,
            'date_from' => $this->period->from->toDateString(),
            'date_to' => $this->period->to->toDateString(),
            'is_default' => $this->period->isDefault,
            'loan_product_id' => $this->loanProductId,
            'status' => $this->status?->value,
            'loan_officer_id' => $this->officerId,
            'branch_id' => $this->branchId,
            'borrower_id' => $this->borrowerId,
            'loan_id' => $this->loanId,
            'disbursement_channel_id' => $this->disbursementBankAccountId,
            'min_days_past_due' => $this->minDaysPastDue,
            'repayment_status' => $this->repaymentStatus,
            'currency' => $this->currency,
        ];
    }

    private static function intOrNull(Request $request, string $key): ?int
    {
        return $request->filled($key) ? (int) $request->integer($key) : null;
    }
}
