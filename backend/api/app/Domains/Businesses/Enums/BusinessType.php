<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Enums;

/**
 * Legal structure of a business.
 *
 * A controlled list, like categories. It determines which registration
 * documents are expected: a Limited Liability Company must produce a CAC
 * certificate, an informal business cannot.
 */
enum BusinessType: string
{
    case SoleProprietorship = 'sole_proprietorship';
    case Partnership = 'partnership';
    case LimitedLiabilityCompany = 'limited_liability_company';
    case Cooperative = 'cooperative';
    case Association = 'association';
    case InformalBusiness = 'informal_business';
    case NonProfitOrganisation = 'non_profit_organisation';
    case OtherRegisteredStructure = 'other_registered_structure';

    public function label(): string
    {
        return match ($this) {
            self::SoleProprietorship => 'Sole Proprietorship',
            self::Partnership => 'Partnership',
            self::LimitedLiabilityCompany => 'Limited Liability Company',
            self::Cooperative => 'Cooperative',
            self::Association => 'Association',
            self::InformalBusiness => 'Informal Business',
            self::NonProfitOrganisation => 'Non-Profit Organisation',
            self::OtherRegisteredStructure => 'Other Registered Structure',
        };
    }

    /**
     * Whether CAC registration is expected for this structure.
     *
     * Informal businesses are a large share of the microfinance book and are
     * legitimately unregistered; demanding a certificate from them would block
     * exactly the merchants this product exists to serve.
     */
    public function requiresCacRegistration(): bool
    {
        return in_array($this, [
            self::LimitedLiabilityCompany,
            self::Cooperative,
            self::NonProfitOrganisation,
        ], true);
    }

    /**
     * @return array<int, array{value: string, label: string, requires_cac: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'requires_cac' => $case->requiresCacRegistration(),
        ], self::cases());
    }
}
