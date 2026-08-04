<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Every permission Naipay grants.
 *
 * Authorisation is always checked against one of these, never against a role
 * name. Roles are an operational convenience that the business reorganises;
 * permissions are the actual control, and a policy that asks "is this user a
 * Branch Manager?" silently breaks the day someone invents a new role that
 * should also have been allowed.
 *
 * Permissions for later phases are declared here from the outset so the role
 * matrix is defined in one place and each phase only has to enforce what it
 * introduces.
 */
enum Permission: string
{
    // --- Dashboard ---------------------------------------------------------
    case DashboardView = 'dashboard.view';

    // --- Merchants ---------------------------------------------------------
    case MerchantsView = 'merchants.view';
    case MerchantsCreate = 'merchants.create';
    case MerchantsUpdate = 'merchants.update';
    case MerchantsApprove = 'merchants.approve';
    case MerchantsSuspend = 'merchants.suspend';
    case MerchantsClose = 'merchants.close';

    /**
     * Unmasked BVN and NIN.
     *
     * Deliberately separate from `merchants.view`: onboarding staff need the
     * profile every day and the identity numbers almost never, so the full
     * values stay behind their own grant and their own audit entry.
     */
    case MerchantsViewSensitive = 'merchants.view_sensitive';

    // --- Businesses --------------------------------------------------------
    case BusinessesView = 'businesses.view';
    case BusinessesCreate = 'businesses.create';
    case BusinessesUpdate = 'businesses.update';
    case BusinessesApprove = 'businesses.approve';

    // --- Business categories -----------------------------------------------
    case CategoriesView = 'categories.view';
    case CategoriesManage = 'categories.manage';

    // --- KYC and documents -------------------------------------------------
    case KycView = 'kyc.view';
    case KycVerify = 'kyc.verify';
    case KycReject = 'kyc.reject';

    case DocumentsView = 'documents.view';
    case DocumentsUpload = 'documents.upload';
    case DocumentsDownload = 'documents.download';
    case DocumentsVerify = 'documents.verify';
    case DocumentsDelete = 'documents.delete';

    // --- Loan products -----------------------------------------------------
    case LoanProductsView = 'loan_products.view';
    case LoanProductsManage = 'loan_products.manage';

    // --- Loan applications -------------------------------------------------
    case LoanApplicationsView = 'loan_applications.view';
    case LoanApplicationsCreate = 'loan_applications.create';
    case LoanApplicationsUpdate = 'loan_applications.update';
    case LoanApplicationsAssess = 'loan_applications.assess';
    case LoanApplicationsRecommend = 'loan_applications.recommend';
    case LoanApplicationsApprove = 'loan_applications.approve';
    case LoanApplicationsReject = 'loan_applications.reject';

    // --- Loans -------------------------------------------------------------
    case LoansView = 'loans.view';
    case LoansCreate = 'loans.create';
    case LoansApprove = 'loans.approve';
    case LoansDisburse = 'loans.disburse';
    case LoansRestructure = 'loans.restructure';
    case LoansWriteOff = 'loans.write_off';
    case LoansClose = 'loans.close';

    // --- Repayments --------------------------------------------------------
    case RepaymentsView = 'repayments.view';
    case RepaymentsRecord = 'repayments.record';
    case RepaymentsVerify = 'repayments.verify';
    case RepaymentsApprove = 'repayments.approve';
    case RepaymentsReverse = 'repayments.reverse';

    // --- Collections -------------------------------------------------------
    case CollectionsView = 'collections.view';
    case CollectionsManage = 'collections.manage';

    // --- Ledger ------------------------------------------------------------
    case LedgerView = 'ledger.view';
    case LedgerPost = 'ledger.post';
    case LedgerReverse = 'ledger.reverse';
    case LedgerManageAccounts = 'ledger.manage_accounts';
    case LedgerManagePeriods = 'ledger.manage_periods';

    // --- Bank accounts and reconciliation ----------------------------------
    case BankAccountsView = 'bank_accounts.view';
    case BankAccountsManage = 'bank_accounts.manage';
    case BankAccountsApprove = 'bank_accounts.approve';

    case ReconciliationView = 'reconciliation.view';
    case ReconciliationMatch = 'reconciliation.match';
    case ReconciliationApprove = 'reconciliation.approve';

    // --- Reports -----------------------------------------------------------
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case ReportsFinancial = 'reports.financial';

    // --- Branches ----------------------------------------------------------
    case BranchesView = 'branches.view';
    case BranchesManage = 'branches.manage';

    // --- Staff and access control ------------------------------------------
    case StaffView = 'staff.view';
    case StaffCreate = 'staff.create';
    case StaffUpdate = 'staff.update';
    case StaffDisable = 'staff.disable';
    case StaffAssignRoles = 'staff.assign_roles';
    case StaffSetApprovalLimit = 'staff.set_approval_limit';

    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    // --- Approvals ---------------------------------------------------------
    case ApprovalsView = 'approvals.view';
    case ApprovalsAct = 'approvals.act';

    // --- Notifications -----------------------------------------------------
    case NotificationsView = 'notifications.view';
    case NotificationsSend = 'notifications.send';

    // --- Audit and settings ------------------------------------------------
    case AuditView = 'audit.view';
    case AuditExport = 'audit.export';

    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';

    /**
     * The module a permission belongs to, derived from its prefix. Used to
     * group the permission matrix in the interface.
     */
    public function module(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Permissions that grant a financially or legally significant capability.
     *
     * Granting any of these is itself an audited, maker-checked change, and
     * holding one requires two-factor authentication.
     *
     * @return array<int, self>
     */
    public static function privileged(): array
    {
        return [
            self::MerchantsViewSensitive,
            self::LoansApprove,
            self::LoansDisburse,
            self::LoansWriteOff,
            self::LoansRestructure,
            self::RepaymentsApprove,
            self::RepaymentsReverse,
            self::LedgerPost,
            self::LedgerReverse,
            self::LedgerManageAccounts,
            self::LedgerManagePeriods,
            self::BankAccountsManage,
            self::BankAccountsApprove,
            self::StaffAssignRoles,
            self::StaffSetApprovalLimit,
            self::RolesManage,
            self::SettingsManage,
        ];
    }

    public function isPrivileged(): bool
    {
        return in_array($this, self::privileged(), true);
    }
}
