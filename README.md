# Naipay

Merchant microfinance administration platform.

Naipay is the internal system used by administrators and staff to onboard merchants and
their businesses, originate and manage loans, record repayments, maintain a double-entry
financial ledger, reconcile against bank statements, and produce the reports and audit
trail the business runs on.

The first release is internal-only. A merchant-facing web portal and mobile application
are planned; the API, database and authentication system are structured so those can be
added without rebuilding the administrative application.

**Production:** https://naipay.naitalk.com

---

## Repository layout

```
naipay/
├── backend/
│   └── api/               Laravel API — the whole domain model and business logic
├── apps/
│   ├── admin-web/         Next.js administrative console
│   ├── merchant-web/      Merchant portal (future phase — placeholder)
│   └── mobile/            React Native app (future phase — placeholder)
├── packages/
│   ├── api-client/        Typed HTTP client, shared by every Naipay frontend
│   ├── shared-types/      TypeScript contracts for the API envelope and domain
│   ├── validation/        Zod schemas mirroring the API's server-side rules
│   └── design-tokens/     Brand palette, typography and spacing
├── infrastructure/
│   ├── deployment/        Deploy scripts and runbooks
│   ├── apache/            Virtual host configuration
│   ├── nginx/             Reverse proxy configuration
│   └── docker/            Container definitions for local parity
└── docs/                  Installation, deployment, schema and domain documentation
```

The frontend is an npm workspace, so `packages/*` are consumed by `apps/*` directly from
source. The merchant portal and mobile app will pick up `api-client`, `shared-types` and
`validation` unchanged.

---

## Technology

| Layer | Choice |
| --- | --- |
| Backend | Laravel 13, PHP 8.3 |
| API | REST, versioned at `/api/v1`, OpenAPI via Scramble |
| Authentication | Laravel Sanctum (bearer tokens) |
| Database | MySQL 8, InnoDB, `utf8mb4` |
| Cache, queue, rate limiting | Redis |
| Admin frontend | Next.js 16, TypeScript, Tailwind CSS 4, React Query |
| Validation | Zod on the client, Form Requests on the server |
| File storage | Local in development, S3-compatible (or MinIO) in production |

---

## Getting started

Prerequisites: PHP 8.3+, Composer 2, Node 20+, MySQL 8, Redis.

```bash
git clone <repository-url> naipay
cd naipay
```

### Backend

```bash
cd backend/api
composer install
cp .env.example .env
php artisan key:generate
```

Create the databases, then configure `DB_*` in `.env`:

```sql
CREATE DATABASE naipay CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE naipay_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

```bash
php artisan migrate
php artisan serve --port=8000
```

### Queue worker

Notifications, exports and report generation run on the queue. Financial postings are
never deferred — they happen inside the request transaction — but the work that follows
them is queued.

```bash
php artisan queue:work redis --queue=naipay-high,naipay-default,naipay-reports
```

### Frontend

```bash
npm install                          # from the repository root
cp apps/admin-web/.env.example apps/admin-web/.env.local
npm run dev
```

The console runs at http://localhost:3000 and expects the API at
http://localhost:8000/api/v1.

---

## Tests

```bash
cd backend/api
php artisan test                     # everything
php artisan test --testsuite=Unit    # financial mathematics, no database
```

Unit tests are pure and fast: all loan calculation, repayment allocation and monetary
arithmetic lives there. Feature tests run against **MySQL, not SQLite** — row locking,
`DECIMAL` precision, strict mode and foreign-key enforcement are precisely what needs
testing, and SQLite would give false confidence on all four.

```bash
npm run typecheck && npm run lint && npm run build
```

---

## API conventions

Every endpoint returns the same envelope, so a client can branch on `success` before it
knows anything about the endpoint it called.

```jsonc
// Success
{
  "success": true,
  "message": "Merchant created successfully.",
  "data": {},
  "meta": {}
}

// Failure
{
  "success": false,
  "message": "Validation failed.",
  "errors": { "field": ["The field is required."] }
}
```

- **Versioning** — `/api/v1`. Administrative endpoints live under `/api/v1/admin`;
  `/api/v1/merchant` is reserved for the merchant portal and mobile app.
- **Pagination** — list endpoints accept `page` and `per_page` and return pagination
  details in `meta.pagination`.
- **Search, filter, sort** — `search`, `sort` (prefix `-` for descending) and per-endpoint
  filters. Every filterable and sortable column is allow-listed.
- **Correlation IDs** — every request carries `X-Correlation-Id` through the logs, queued
  jobs and audit trail. Clients may supply their own.
- **Timestamps** — stored in UTC, rendered in the operator's timezone.
- **Currency** — Nigerian Naira by default.

Interactive documentation runs at `/docs/api` and the OpenAPI document at
`/docs/api.json`.

---

## Engineering rules

These are load-bearing. Financial correctness in this system depends on them.

- **Money is never a float.** Monetary columns are `DECIMAL(20,2)`; in PHP they are
  handled by the `Money` value object, which holds an integer count of kobo. A rounding
  drift of one kobo per instalment compounds into a schedule that will not close out.
- **Business logic never lives in controllers.** It belongs to a domain service under
  `app/Domains/<Domain>/Services`.
- **Nothing operational is hard-coded.** Repayment allocation order, approval limits,
  loan mechanics and duplicate-detection rules are read from `config/naipay.php` at
  runtime.
- **Balances only move with the ledger.** A loan balance is never updated without a
  corresponding balanced journal transaction in the same database transaction.
- **Posted entries are immutable.** Approved repayments are never deleted and journal
  entries are never edited. Corrections are reversals that leave the original intact.
- **Maker–checker is enforced centrally.** A staff member can never approve an operation
  they created.
- **Sensitive identity data is masked.** Full BVN and NIN values never appear in list
  views, logs or audit payloads.

---

## Documentation

| Document | Contents |
| --- | --- |
| [`docs/architecture.md`](docs/architecture.md) | Domain structure and design decisions |
| [`docs/roles-and-permissions.md`](docs/roles-and-permissions.md) | The role and permission matrix |
| [`docs/environment-variables.md`](docs/environment-variables.md) | Every environment variable |
| [`docs/installation.md`](docs/installation.md) | Full local setup |
| [`docs/deployment.md`](docs/deployment.md) | Deploying to `naipay.naitalk.com` |

Domain documentation — loan calculation, repayment allocation, ledger posting, the
reversal process, and the role and permission matrix — is written alongside the phase
that introduces it.

---

## Development status

Delivered in phases, each independently deployable and tested before the next begins.

Administrative screens for phases 2 onwards are deliberately deferred to Phase 16, where the
design system they depend on is built. Backend work runs ahead of the interface.

| Phase | Scope | Status |
| --- | --- | --- |
| 0 | Project foundation | ✅ Complete |
| 1 | Authentication and access control | ✅ Complete |
| 2 | Branch, staff and organisational management | ✅ API complete |
| 3 | Business categories | ✅ API complete |
| 4 | Merchant and business onboarding | ✅ API complete |
| 5 | Documents and KYC | ✅ API complete |
| 6 | Loan products | ✅ API complete |
| 7 | Loan applications | ✅ API complete |
| 8 | Loans and disbursement | ✅ API complete |
| 9 | Manual repayments | Pending |
| 10 | Designated bank accounts | ✅ API complete |
| 11 | Double-entry ledger | ✅ API complete |
| 12 | Manual bank reconciliation | Pending |
| 13 | Dashboard and reports | Pending |
| 14 | Notifications and receipts | Pending |
| 15 | Audit and compliance | Pending |
| 16 | Administrative UI | Pending |
| 17 | Security | Pending |
| 18 | Testing | Pending |
| 19 | Deployment | Pending |

Not in the first release: merchant portal, mobile application, virtual accounts, payment
gateways, direct bank APIs, public business directory, automated business connections,
card payments and automated direct debit. The architecture accommodates all of them.
