<?php

declare(strict_types=1);

namespace App\Domains\Documents\Enums;

/**
 * What a document type attaches to.
 *
 * Kept as an enum rather than free text so the picker on each screen can offer
 * exactly the document types that make sense there.
 */
enum DocumentOwnerType: string
{
    case Merchant = 'merchant';
    case Business = 'business';
    case Loan = 'loan';
    case Guarantor = 'guarantor';
    case Collateral = 'collateral';
    case Repayment = 'repayment';

    public function label(): string
    {
        return match ($this) {
            self::Merchant => 'Merchant',
            self::Business => 'Business',
            self::Loan => 'Loan',
            self::Guarantor => 'Guarantor',
            self::Collateral => 'Collateral',
            self::Repayment => 'Repayment',
        };
    }
}
