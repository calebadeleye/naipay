# Architecture

## Shape

Naipay is an **API-first modular monolith**. One deployable Laravel application, divided
internally into business domains with explicit boundaries.

Microservices were not the starting point deliberately. The hardest correctness problems
here — a repayment that must allocate across buckets, post a balanced journal transaction,
update a schedule and adjust loan balances *atomically* — are trivially solved by a
database transaction inside one process, and genuinely difficult across a network. The
domain boundaries below are drawn so a high-volume module can be extracted later if
volume demands it, but nothing is paid for that possibility today.

## Domains

Each domain under `backend/api/app/Domains/` owns its models, services, repositories,
controllers, requests, policies, events, listeners, jobs, migrations and tests.

| Domain | Responsibility |
| --- | --- |
| `Identity` | Authentication, sessions, two-factor, roles, permissions |
| `Staff` | Staff records, approval limits, activity history |
| `Branches` | Branch structure and access scoping |
| `Merchants` | The individual or principal account holder |
| `Businesses` | The commercial enterprise a merchant operates |
| `KYC` | Verification workflow and status |
| `Accounts` | Internal financial account structure per merchant |
| `Ledger` | Chart of accounts, journal transactions and entries, balances, periods |
| `Loans` | Loan accounts, schedules, statuses, disbursement |
| `LoanProducts` | Product configuration and interest calculation |
| `Repayments` | Recording, verification, approval, allocation, reversal |
| `Collections` | Delinquency follow-up |
| `Approvals` | Maker–checker and multi-level approval workflows |
| `Reconciliation` | Bank statement matching |
| `Notifications` | Channel-agnostic delivery |
| `Reports` | Report generation and export |
| `Documents` | Storage, verification and expiry of uploaded files |
| `Audit` | The immutable audit trail |
| `Integrations` | Interfaces for future payment and virtual-account providers |

Migrations are discovered automatically from every `app/Domains/*/Database/Migrations`
directory. Laravel orders migrations by filename across all registered paths, so
timestamp prefixes must reflect real dependency order where a foreign key crosses
domains.

### Shared foundation

`app/Support/` holds what every domain depends on and no domain owns:

- `Money/` — the monetary value object, its rounding policy and Eloquent cast
- `Http/` — the response envelope, correlation IDs, security headers
- `Query/` — allow-listed search, filter, sort and pagination
- `Sequences/` — collision-safe reference generation
- `Logging/` — structured logging with sensitive-data redaction
- `Exceptions/` — the domain exception hierarchy and API renderer

## Key decisions

### Money is an integer, never a float

`Money` holds an integer count of minor units (kobo). Floats are used nowhere in the
lifecycle of an amount.

This is not fastidiousness. `0.1 + 0.2 !== 0.3` in IEEE-754, and a loan balance that
disagrees with the sum of its instalments by a kobo is a defect an auditor will find.
`Money::allocateEvenly()` distributes remainder kobo across the leading instalments so a
schedule sums back to its principal exactly.

The database counterpart is `DECIMAL(20,2)`, cast through `MoneyCast`.

### Financial truth lives in the ledger

Loan balance columns exist for query performance. They are not the source of truth — the
double-entry ledger is. Every balance movement is accompanied by a balanced journal
transaction posted in the same database transaction, and the two can always be
reconciled against each other.

Posted journal entries are immutable. Corrections are compensating reversal entries that
leave the original permanently intact.

### Operational policy is configuration

`config/naipay.php` holds repayment allocation order, duplicate-detection rules,
maker–checker operations, delinquency thresholds, ageing buckets and reference formats.
Credit and finance teams change these without a deployment, and the engineering brief is
explicit that none of them may be hard-coded.

### References are allocated atomically

`ReferenceGenerator` advances a counter with a single
`INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID(...)` statement. MySQL locks the
sequence row for the enclosing transaction, so concurrent allocations serialise and two
records can never receive the same reference.

Gaps are acceptable — a rolled-back transaction releases its number, and nothing assumes
references are contiguous. Reuse is not acceptable, and cannot occur.

The trade-off: the sequence row stays locked until the outer transaction commits, so
references are allocated late, once the surrounding work is certain to succeed.

### Everything is allow-listed

`QuerySpecification` names exactly which columns an endpoint may be searched, filtered or
sorted by. An unrecognised parameter is ignored rather than passed through, so no request
can order by or filter on a column it was never meant to reach.

### Errors never leak internals

`ApiExceptionRenderer` maps every exception to the standard envelope. Domain rule
violations return their own message and a 4xx. Unexpected failures return a generic
message plus the correlation ID — an operator can quote it to support, and an engineer
can find the full trace in the logs, without any of it crossing the wire.

## Separating administrative and merchant surfaces

```
/api/v1/admin/...      internal staff (this release)
/api/v1/merchant/...   merchant portal and mobile app (future phase)
```

The split exists from the first commit. Merchant authentication will be a separate guard
with its own permissions, so a merchant token can never reach an administrative endpoint
regardless of how routes evolve.

The frontend mirrors this: `packages/api-client`, `packages/shared-types` and
`packages/validation` carry no admin-specific assumptions, and the merchant portal and
React Native app will consume them unchanged.

## Provider independence

Manual repayment recording is the only payment path in the first release. Virtual
accounts and payment gateways arrive later, behind interfaces in `Domains/Integrations`.

Critically, an automated repayment will enter the **same** allocation and ledger services
as a manual one. There is no second implementation of financial logic per provider, and
no provider type may branch inside those services.
