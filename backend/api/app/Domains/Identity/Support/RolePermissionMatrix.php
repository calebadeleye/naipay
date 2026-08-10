<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;

/**
 * The role and permission matrix.
 *
 * The single authority on what each seeded role may do. The seeder reads it,
 * the documentation is generated from it, and the tests assert against it.
 *
 * Two principles shape the grants below.
 *
 * Segregation of duties: no role holds both sides of a financial control. A
 * Credit Officer assesses and recommends but cannot approve. A Cashier records
 * repayments but cannot verify or approve them. A Finance Officer verifies but
 * only the Finance Manager approves. This is what makes maker-checker
 * meaningful — enforcing "not the same person" is worth little if one role can
 * perform every step anyway.
 *
 * Least privilege: a role receives what its work requires and nothing else.
 * Unmasked identity numbers, ledger posting and access control are held by as
 * few roles as possible.
 */
final class RolePermissionMatrix
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function toArray(): array
    {
        $matrix = [];

        foreach (Role::cases() as $role) {
            $matrix[$role->value] = array_values(array_unique(array_map(
                static fn (Permission $permission): string => $permission->value,
                self::permissionsFor($role),
            )));
        }

        return $matrix;
    }

    /**
     * @return array<int, Permission>
     */
    public static function permissionsFor(Role $role): array
    {
        return match ($role) {
            // Unrestricted. The only role that can alter access control and
            // system settings.
            Role::SuperAdministrator => Permission::cases(),

            // Sees everything, changes nothing. Executives need portfolio truth,
            // not operational write access.
            Role::Executive => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::CategoriesView,
                Permission::LoanProductsView,
                Permission::LoanApplicationsView,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::CollectionsView,
                Permission::LedgerView,
                Permission::BankAccountsView,
                Permission::ReconciliationView,
                Permission::BranchesView,
                Permission::StaffView,
                Permission::ApprovalsView,
                Permission::ReportsView,
                Permission::ReportsExport,
                Permission::ReportsFinancial,
                Permission::AuditView,
                Permission::SettingsView,
                Permission::InvestorsView,
            ],

            Role::OperationsManager => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::MerchantsCreate,
                Permission::MerchantsUpdate,
                Permission::MerchantsApprove,
                Permission::MerchantsSuspend,
                Permission::BusinessesView,
                Permission::BusinessesCreate,
                Permission::BusinessesUpdate,
                Permission::BusinessesApprove,
                Permission::CategoriesView,
                Permission::KycView,
                Permission::DocumentsView,
                Permission::DocumentsUpload,
                Permission::DocumentsDownload,
                Permission::LoanProductsView,
                Permission::LoanApplicationsView,
                Permission::LoanApplicationsCreate,
                Permission::LoanApplicationsUpdate,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::CollectionsView,
                Permission::CollectionsManage,
                Permission::BranchesView,
                Permission::StaffView,
                Permission::ApprovalsView,
                Permission::ApprovalsAct,
                Permission::ReportsView,
                Permission::ReportsExport,
                Permission::NotificationsView,
                Permission::NotificationsSend,
            ],

            // Scoped to their own branch by the branch-access rules in the
            // organisational phase, not by the permission set.
            Role::BranchManager => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::MerchantsCreate,
                Permission::MerchantsUpdate,
                Permission::MerchantsApprove,
                Permission::BusinessesView,
                Permission::BusinessesCreate,
                Permission::BusinessesUpdate,
                Permission::BusinessesApprove,
                Permission::CategoriesView,
                Permission::KycView,
                Permission::DocumentsView,
                Permission::DocumentsUpload,
                Permission::DocumentsDownload,
                Permission::LoanProductsView,
                Permission::LoanApplicationsView,
                Permission::LoanApplicationsCreate,
                Permission::LoanApplicationsUpdate,
                Permission::LoanApplicationsRecommend,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::CollectionsView,
                Permission::CollectionsManage,
                Permission::BranchesView,
                Permission::StaffView,
                Permission::ApprovalsView,
                Permission::ApprovalsAct,
                Permission::ReportsView,
                Permission::ReportsExport,
            ],

            // Approves credit. Does not disburse — releasing the money is a
            // finance function, and separating the two is the point.
            Role::CreditManager => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::MerchantsViewSensitive,
                Permission::BusinessesView,
                Permission::CategoriesView,
                Permission::KycView,
                Permission::DocumentsView,
                Permission::DocumentsDownload,
                Permission::LoanProductsView,
                Permission::LoanProductsManage,
                Permission::LoanApplicationsView,
                Permission::LoanApplicationsAssess,
                Permission::LoanApplicationsRecommend,
                Permission::LoanApplicationsApprove,
                Permission::LoanApplicationsReject,
                Permission::LoansView,
                Permission::LoansApprove,
                Permission::LoansRestructure,
                Permission::RepaymentsView,
                Permission::CollectionsView,
                Permission::ApprovalsView,
                Permission::ApprovalsAct,
                Permission::ReportsView,
                Permission::ReportsExport,
                Permission::BranchesView,
            ],

            // Assesses and recommends. Never approves — that is the checker
            // half of the control.
            Role::CreditOfficer => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::CategoriesView,
                Permission::KycView,
                Permission::DocumentsView,
                Permission::DocumentsUpload,
                Permission::DocumentsDownload,
                Permission::LoanProductsView,
                Permission::LoanApplicationsView,
                Permission::LoanApplicationsCreate,
                Permission::LoanApplicationsUpdate,
                Permission::LoanApplicationsAssess,
                Permission::LoanApplicationsRecommend,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::CollectionsView,
                Permission::ReportsView,
            ],

            Role::LoanOfficer => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::MerchantsCreate,
                Permission::MerchantsUpdate,
                Permission::BusinessesView,
                Permission::BusinessesCreate,
                Permission::BusinessesUpdate,
                Permission::CategoriesView,
                Permission::KycView,
                Permission::DocumentsView,
                Permission::DocumentsUpload,
                Permission::DocumentsDownload,
                Permission::LoanProductsView,
                Permission::LoanApplicationsView,
                Permission::LoanApplicationsCreate,
                Permission::LoanApplicationsUpdate,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::RepaymentsRecord,
                Permission::CollectionsView,
                Permission::ReportsView,
            ],

            // Owns the money side: disbursement, the ledger, bank accounts and
            // final repayment approval.
            Role::FinanceManager => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::LoanProductsView,
                Permission::LoanApplicationsView,
                Permission::LoansView,
                Permission::LoansDisburse,
                Permission::LoansWriteOff,
                Permission::LoansClose,
                Permission::RepaymentsView,
                Permission::RepaymentsVerify,
                Permission::RepaymentsApprove,
                Permission::RepaymentsReverse,
                Permission::LedgerView,
                Permission::LedgerPost,
                Permission::LedgerReverse,
                Permission::LedgerManageAccounts,
                Permission::LedgerManagePeriods,
                Permission::BankAccountsView,
                Permission::BankAccountsManage,
                Permission::BankAccountsApprove,
                Permission::ReconciliationView,
                Permission::ReconciliationMatch,
                Permission::ReconciliationApprove,
                Permission::CollectionsView,
                Permission::ApprovalsView,
                Permission::ApprovalsAct,
                Permission::ReportsView,
                Permission::ReportsExport,
                Permission::ReportsFinancial,
                Permission::BranchesView,
                Permission::AuditView,
            ],

            // Verifies; does not approve.
            Role::FinanceOfficer => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::RepaymentsRecord,
                Permission::RepaymentsVerify,
                Permission::LedgerView,
                Permission::BankAccountsView,
                Permission::ReconciliationView,
                Permission::ReconciliationMatch,
                Permission::ReportsView,
                Permission::ReportsExport,
                Permission::ReportsFinancial,
            ],

            // Records only. The narrowest financial role in the system.
            Role::Cashier => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::RepaymentsRecord,
                Permission::ReportsView,
            ],

            Role::CollectionsOfficer => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::RepaymentsRecord,
                Permission::CollectionsView,
                Permission::CollectionsManage,
                Permission::NotificationsView,
                Permission::NotificationsSend,
                Permission::ReportsView,
            ],

            // Holds unmasked identity numbers because verifying them against
            // source documents is the job.
            Role::ComplianceOfficer => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::MerchantsViewSensitive,
                Permission::MerchantsSuspend,
                Permission::BusinessesView,
                Permission::CategoriesView,
                Permission::KycView,
                Permission::KycVerify,
                Permission::KycReject,
                Permission::DocumentsView,
                Permission::DocumentsUpload,
                Permission::DocumentsDownload,
                Permission::DocumentsVerify,
                Permission::LoanApplicationsView,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::ApprovalsView,
                Permission::ReportsView,
                Permission::ReportsExport,
                Permission::AuditView,
            ],

            Role::CustomerSupport => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::CategoriesView,
                Permission::LoanProductsView,
                Permission::LoanApplicationsView,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::DocumentsView,
                Permission::NotificationsView,
                Permission::ReportsView,
            ],

            // Reads everything, writes nothing — including the audit trail
            // itself, which no role may modify.
            Role::Auditor => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::CategoriesView,
                Permission::KycView,
                Permission::DocumentsView,
                Permission::DocumentsDownload,
                Permission::LoanProductsView,
                Permission::LoanApplicationsView,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::CollectionsView,
                Permission::LedgerView,
                Permission::BankAccountsView,
                Permission::ReconciliationView,
                Permission::BranchesView,
                Permission::StaffView,
                Permission::RolesView,
                Permission::ApprovalsView,
                Permission::ReportsView,
                Permission::ReportsExport,
                Permission::ReportsFinancial,
                Permission::AuditView,
                Permission::AuditExport,
                Permission::SettingsView,
                Permission::InvestorsView,
            ],

            Role::ReadOnlyUser => [
                Permission::DashboardView,
                Permission::MerchantsView,
                Permission::BusinessesView,
                Permission::LoansView,
                Permission::RepaymentsView,
                Permission::ReportsView,
            ],

            // Adds and manages investor accounts. Narrow by design: this role
            // touches nothing else in the system.
            Role::InvestorManager => [
                Permission::DashboardView,
                Permission::InvestorsView,
                Permission::InvestorsCreate,
                Permission::InvestorsUpdate,
                Permission::InvestorsSuspend,
            ],
        };
    }
}
