<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * The seeded roles.
 *
 * A role is a named bundle of permissions, nothing more. Authorisation is
 * always decided on a permission — see Permission — so these can be
 * reorganised without touching a policy.
 *
 * The permission grants themselves live in RolePermissionMatrix, which is the
 * single place the role and permission matrix is defined.
 */
enum Role: string
{
    case SuperAdministrator = 'super-administrator';
    case Executive = 'executive';
    case OperationsManager = 'operations-manager';
    case BranchManager = 'branch-manager';
    case CreditManager = 'credit-manager';
    case CreditOfficer = 'credit-officer';
    case LoanOfficer = 'loan-officer';
    case FinanceManager = 'finance-manager';
    case FinanceOfficer = 'finance-officer';
    case Cashier = 'cashier';
    case CollectionsOfficer = 'collections-officer';
    case ComplianceOfficer = 'compliance-officer';
    case CustomerSupport = 'customer-support';
    case Auditor = 'auditor';
    case ReadOnlyUser = 'read-only-user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Super Administrator',
            self::Executive => 'Executive',
            self::OperationsManager => 'Operations Manager',
            self::BranchManager => 'Branch Manager',
            self::CreditManager => 'Credit Manager',
            self::CreditOfficer => 'Credit Officer',
            self::LoanOfficer => 'Loan Officer',
            self::FinanceManager => 'Finance Manager',
            self::FinanceOfficer => 'Finance Officer',
            self::Cashier => 'Cashier',
            self::CollectionsOfficer => 'Collections Officer',
            self::ComplianceOfficer => 'Compliance Officer',
            self::CustomerSupport => 'Customer Support',
            self::Auditor => 'Auditor',
            self::ReadOnlyUser => 'Read-only User',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Unrestricted access, including system settings and access control.',
            self::Executive => 'Portfolio-wide visibility and reporting, without operational write access.',
            self::OperationsManager => 'Oversees merchant onboarding, loan operations and branch activity.',
            self::BranchManager => 'Manages a branch: its staff, merchants, loans and collections.',
            self::CreditManager => 'Approves credit within limit and owns loan product configuration.',
            self::CreditOfficer => 'Performs credit assessment and recommends applications for approval.',
            self::LoanOfficer => 'Onboards merchants and originates loan applications.',
            self::FinanceManager => 'Owns the ledger, bank accounts, disbursement and reconciliation.',
            self::FinanceOfficer => 'Verifies repayments and performs day-to-day reconciliation.',
            self::Cashier => 'Records repayments received.',
            self::CollectionsOfficer => 'Follows up overdue loans and manages collections.',
            self::ComplianceOfficer => 'Verifies KYC and reviews documentation for compliance.',
            self::CustomerSupport => 'Read access to merchant and loan records to answer enquiries.',
            self::Auditor => 'Read-only access across the system, including the audit trail.',
            self::ReadOnlyUser => 'Minimal read-only access.',
        };
    }

    /**
     * Roles for which two-factor authentication cannot be switched off.
     *
     * These hold permissions that move money, change access, or expose
     * identity data, so a stolen password alone must not be sufficient.
     *
     * @return array<int, self>
     */
    public static function requiringTwoFactor(): array
    {
        return [
            self::SuperAdministrator,
            self::Executive,
            self::OperationsManager,
            self::CreditManager,
            self::FinanceManager,
            self::ComplianceOfficer,
            self::Auditor,
        ];
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this, self::requiringTwoFactor(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
