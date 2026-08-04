# Naipay Merchant Portal

Placeholder. Implemented in a later phase (Future Phase 20).

The merchant-facing portal will let merchants view their profile, businesses, accounts,
loans and repayment schedules, download statements and receipts, apply for loans, upload
supporting documents, track applications and manage authorised devices.

It will consume the same workspace packages as the administrative console:

- `@naipay/api-client`
- `@naipay/shared-types`
- `@naipay/validation`
- `@naipay/design-tokens`

Merchant endpoints are served under `/api/v1/merchant`, behind a separate authentication
guard with its own permissions. Nothing in this directory should be built until the
backend merchant guard exists.
