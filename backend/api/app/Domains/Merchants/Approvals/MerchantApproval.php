<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Approvals;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Merchants\Models\Merchant;

/**
 * Adapts a merchant to the maker-checker contract for its approval step.
 *
 * The guard only needs to know who created the record and which operation is
 * being attempted; this keeps that knowledge out of the merchant model.
 */
final class MerchantApproval implements RequiresMakerChecker
{
    public function __construct(
        private readonly Merchant $merchant,
    ) {}

    public function makerId(): ?int
    {
        return $this->merchant->created_by;
    }

    public function makerCheckerOperation(): string
    {
        return 'merchant.approve';
    }
}
