<?php

declare(strict_types=1);

namespace App\Domains\Loans\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Turns an approved loan application into a loan.
 *
 * Called from `LoanApplicationService::approve()`, inside the same
 * transaction, the same way `MerchantOnboardingService::approve()` opens the
 * merchant's account — an application can never end up Approved without a
 * loan existing for it, and vice versa.
 *
 * Terms are snapshotted here rather than read from the product at
 * disbursement time: the product may be repriced between now and then, and
 * this loan's contract must not move with it.
 */
final class LoanCreationService
{
    private const MODULE = 'loans';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    public function createFromApplication(LoanApplication $application, Staff $actor): Loan
    {
        return DB::transaction(function () use ($application, $actor): Loan {
            /** @var LoanProduct $product */
            $product = $application->loanProduct()->firstOrFail();

            $loan = new Loan([
                'loan_application_id' => $application->getKey(),
                'merchant_id' => $application->merchant_id,
                'business_id' => $application->business_id,
                'loan_product_id' => $application->loan_product_id,
                'branch_id' => $application->branch_id,
                'principal_amount' => $application->approved_amount,
                'interest_method' => $product->interest_method,
                'interest_rate' => $application->approved_interest_rate,
                'repayment_frequency' => $product->repayment_frequency,
                'tenor' => $application->approved_tenor,
                'grace_period_days' => $product->grace_period_days,
            ]);

            $loan->loan_reference = $this->references->next('loan');
            $loan->status = LoanStatus::PendingApproval;
            $loan->created_by = $actor->getKey();
            $loan->save();

            $this->audit->recordCreation('loan.created', self::MODULE, $loan, $actor);

            return $loan->fresh();
        });
    }
}
