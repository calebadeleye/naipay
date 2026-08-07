<?php

declare(strict_types=1);

use App\Support\Money\Currency;

/**
 * Every Merchant domain configuration.
 *
 * Operational policy lives here rather than in code. The engineering brief is
 * explicit that repayment allocation order, approval limits and loan mechanics
 * must not be hard-coded — finance and credit teams change these without a
 * deployment, and every value is read through a service at runtime.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | Used consistently across the interface, emails, receipts, statements and
    | system-generated references.
    |
    */

    'brand' => [
        'name' => 'Every Merchant',
        'legal_name' => env('NAIPAY_LEGAL_NAME', 'Every Merchant Microfinance'),
        'tagline' => 'Merchant Microfinance',
        'support_email' => env('NAIPAY_SUPPORT_EMAIL', 'support@naitalk.com'),
        'support_phone' => env('NAIPAY_SUPPORT_PHONE'),
        'website' => env('NAIPAY_WEBSITE', 'https://everymerchant.naitalk.com'),
        'logo_path' => env('NAIPAY_LOGO_PATH', 'branding/every-merchant-logo.png'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency and locale
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC throughout; the display timezone is applied
    | at the presentation layer only.
    |
    */

    'currency' => env('NAIPAY_CURRENCY', Currency::DEFAULT),
    'display_timezone' => env('NAIPAY_DISPLAY_TIMEZONE', 'Africa/Lagos'),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Named by priority. Security-relevant mail (password resets, login alerts)
    | and receipts go on `high`; bulk exports and report generation go on `low`
    | so a large report can never delay them.
    |
    */

    'queues' => [
        'high' => env('QUEUE_HIGH', 'naipay-high'),
        'default' => env('QUEUE_DEFAULT', 'naipay-default'),
        'low' => env('QUEUE_LOW', 'naipay-reports'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reference number formats
    |--------------------------------------------------------------------------
    |
    | `{YEAR}` is replaced with the four-digit year of issue and `{SEQ}` with a
    | zero-padded counter allocated atomically. Sequences reset annually only
    | where the pattern contains {YEAR}.
    |
    */

    'references' => [
        'merchant' => ['pattern' => 'NPM-{SEQ}', 'padding' => 6],
        'business' => ['pattern' => 'NPB-{SEQ}', 'padding' => 6],
        'loan_application' => ['pattern' => 'NPA-{YEAR}-{SEQ}', 'padding' => 6],
        'loan' => ['pattern' => 'NPL-{YEAR}-{SEQ}', 'padding' => 6],
        'repayment' => ['pattern' => 'NPR-{YEAR}-{SEQ}', 'padding' => 6],
        'receipt' => ['pattern' => 'NPT-{YEAR}-{SEQ}', 'padding' => 6],
        'journal' => ['pattern' => 'NPJ-{YEAR}-{SEQ}', 'padding' => 6],
        'reversal' => ['pattern' => 'NPV-{YEAR}-{SEQ}', 'padding' => 6],
        'staff' => ['pattern' => 'NPS-{SEQ}', 'padding' => 5],
        'branch' => ['pattern' => 'NPBR-{SEQ}', 'padding' => 3],
        'investor' => ['pattern' => 'NPI-{SEQ}', 'padding' => 5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Repayment allocation
    |--------------------------------------------------------------------------
    |
    | The order in which an approved repayment is consumed. Editable by an
    | authorised administrator; the default follows standard microfinance
    | practice of clearing punitive and overdue balances first.
    |
    | Each entry names a bucket the allocation engine understands. Removing an
    | entry means that bucket is never allocated to and will accumulate.
    |
    */

    'allocation' => [
        'default_order' => [
            'penalty',
            'fee',
            'overdue_interest',
            'current_interest',
            'overdue_principal',
            'current_principal',
        ],

        // Where money beyond the total outstanding is placed. `excess` holds it
        // against the loan for the next instalment; `unallocated` parks it for
        // an officer to assign.
        'surplus_bucket' => env('NAIPAY_ALLOCATION_SURPLUS_BUCKET', 'excess'),

        // Allow a payment larger than the total outstanding to settle the loan
        // and retain the remainder, rather than being rejected outright.
        'allow_overpayment' => env('NAIPAY_ALLOW_OVERPAYMENT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate repayment detection
    |--------------------------------------------------------------------------
    |
    | A candidate repayment is compared against recent records. Matching every
    | field in a `block` set is refused outright; matching a `warn` set raises a
    | confirmable warning so an officer can override a genuine repeat transfer.
    |
    */

    'duplicate_detection' => [
        'lookback_days' => env('NAIPAY_DUPLICATE_LOOKBACK_DAYS', 30),

        'block' => [
            ['receiving_bank_account_id', 'bank_reference'],
        ],

        'warn' => [
            ['receiving_bank_account_id', 'amount', 'payment_date', 'sender_account_name'],
            ['loan_id', 'amount', 'payment_date'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Maker-checker
    |--------------------------------------------------------------------------
    |
    | Operations that may never be approved by the staff member who created
    | them. Enforced centrally by the approval service, not per controller.
    |
    */

    'maker_checker' => [
        'enforced_operations' => [
            'loan.approve',
            'loan.disburse',
            'repayment.approve',
            'repayment.reverse',
            'ledger.manual_credit',
            'ledger.manual_debit',
            'loan.interest_rate_change',
            'loan.restructure',
            'loan.write_off',
            'account.close',
            'loan_application.approve',
            'merchant.approve',
            'merchant.sensitive_update',
            'staff.role_change',
            'staff.approval_limit_change',
            'bank_account.change',
            'reconciliation.approve',
        ],

        // When true, a staff member cannot approve an operation created by
        // anyone reporting to them either. Reserved for a later phase.
        'block_subordinate_approvals' => env('NAIPAY_BLOCK_SUBORDINATE_APPROVALS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Loan defaults
    |--------------------------------------------------------------------------
    |
    | Fallbacks applied only where a loan product does not state its own value.
    | Product configuration always wins.
    |
    */

    'loans' => [
        'default_grace_period_days' => env('NAIPAY_DEFAULT_GRACE_PERIOD_DAYS', 0),

        // Days past due at which a loan moves through each classification.
        'delinquency_thresholds' => [
            'past_due' => 1,
            'delinquent' => 30,
            'non_performing' => 90,
        ],

        // Ageing buckets used by the delinquency report and portfolio-at-risk.
        'ageing_buckets' => [
            ['label' => 'Current', 'from' => 0, 'to' => 0],
            ['label' => '1-30 days', 'from' => 1, 'to' => 30],
            ['label' => '31-60 days', 'from' => 31, 'to' => 60],
            ['label' => '61-90 days', 'from' => 61, 'to' => 90],
            ['label' => '91-180 days', 'from' => 91, 'to' => 180],
            ['label' => 'Over 180 days', 'from' => 181, 'to' => null],
        ],

        // Portfolio at risk is measured from this many days past due.
        'par_threshold_days' => env('NAIPAY_PAR_THRESHOLD_DAYS', 30),

        /*
         * Public holidays, as Y-m-d.
         *
         * Daily-collection instalments never fall on these, nor at the weekend.
         * Kept in configuration because Nigerian public holidays move with the
         * lunar calendar and are sometimes announced only days in advance —
         * waiting for a deployment would put instalments on a day nobody is
         * collecting.
         */
        'public_holidays' => [
            // '2026-01-01', // New Year's Day
            // '2026-05-01', // Workers' Day
            // '2026-06-12', // Democracy Day
            // '2026-10-01', // Independence Day
            // '2026-12-25', // Christmas Day
            // '2026-12-26', // Boxing Day
        ],

        // A loan application expires if it is not approved within this window.
        'application_validity_days' => env('NAIPAY_APPLICATION_VALIDITY_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger
    |--------------------------------------------------------------------------
    */

    'ledger' => [
        // Refuse to post into a closed accounting period.
        'enforce_period_lock' => env('NAIPAY_ENFORCE_PERIOD_LOCK', true),

        // Refuse a posting dated further ahead than this many days.
        'max_future_posting_days' => env('NAIPAY_MAX_FUTURE_POSTING_DAYS', 0),

        // Retention window for idempotency keys guarding duplicate postings.
        'idempotency_retention_days' => env('NAIPAY_IDEMPOTENCY_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    'documents' => [
        'disk' => env('NAIPAY_DOCUMENT_DISK', 'documents'),
        'max_upload_kilobytes' => env('NAIPAY_MAX_UPLOAD_KB', 10240),

        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
        ],

        // Days before an expiring document at which a reminder is raised.
        'expiry_reminder_days' => [30, 14, 7, 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 200,

        // Result sets larger than this must be requested as a queued export
        // rather than paged through interactively.
        'export_queue_threshold' => env('NAIPAY_EXPORT_QUEUE_THRESHOLD', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    'security' => [
        // Consecutive failed logins before the account is locked.
        'max_login_attempts' => env('NAIPAY_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes' => env('NAIPAY_LOCKOUT_MINUTES', 30),

        // Idle time after which an administrative session is terminated.
        'session_idle_minutes' => env('NAIPAY_SESSION_IDLE_MINUTES', 30),
        'token_lifetime_minutes' => env('NAIPAY_TOKEN_LIFETIME_MINUTES', 480),

        // Roles for which two-factor authentication cannot be switched off.
        'mandatory_two_factor_roles' => [
            'super-administrator',
            'executive',
            'operations-manager',
            'finance-manager',
            'credit-manager',
            'compliance-officer',
            'auditor',
        ],

        // Operations requiring the actor to re-enter their password or a 2FA
        // code at the point of action, regardless of an active session.
        'reauthentication_required_operations' => [
            'loan.disburse',
            'repayment.reverse',
            'ledger.manual_credit',
            'ledger.manual_debit',
            'loan.write_off',
            'bank_account.change',
            'staff.role_change',
            'settings.manage',
        ],

        // Operations above where a password alone satisfies reauthentication
        // even when the account has two-factor enabled — a lower bar than
        // the financial operations above it, which always demand a
        // two-factor-backed reauthentication when the account has one.
        'reauthentication_two_factor_optional_operations' => [
            'staff.role_change',
        ],

        // How long a step-up authentication remains valid before the next
        // sensitive operation demands another one.
        'reauthentication_window_minutes' => env('NAIPAY_REAUTHENTICATION_WINDOW_MINUTES', 15),

        // Request keys scrubbed from logs and audit payloads.
        'redacted_keys' => [
            'password',
            'password_confirmation',
            'current_password',
            'bvn',
            'nin',
            'token',
            'access_token',
            'refresh_token',
            'two_factor_code',
            'two_factor_secret',
            'recovery_codes',
            'authorization',
            'cookie',
        ],
    ],

];
