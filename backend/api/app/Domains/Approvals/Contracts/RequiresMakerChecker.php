<?php

declare(strict_types=1);

namespace App\Domains\Approvals\Contracts;

/**
 * Implemented by any record whose approval is subject to segregation of
 * duties — loan applications, repayments, reversals, bank account changes.
 *
 * The contract is deliberately minimal: the guard only needs to know who
 * created the record and what operation is being attempted.
 */
interface RequiresMakerChecker
{
    /**
     * The staff member who created the record — the "maker".
     *
     * Null where the record was produced by the system rather than a person,
     * in which case any authorised approver may act on it.
     */
    public function makerId(): ?int;

    /**
     * The operation key, matching an entry in
     * `naipay.maker_checker.enforced_operations` — e.g. 'repayment.approve'.
     */
    public function makerCheckerOperation(): string;
}
