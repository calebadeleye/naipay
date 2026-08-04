# Naipay Mobile

Placeholder. Implemented in a later phase (Future Phase 22).

A React Native application sharing the merchant portal's API. Planned functionality:
secure and biometric login, account and loan overview, repayment schedules, loan
applications, document upload, receipts, statements, notifications, support, business
profile and device management.

It will consume `@naipay/api-client`, `@naipay/shared-types` and `@naipay/validation`
unchanged — the API client is deliberately transport-agnostic (plain `fetch`, no
framework coupling) so it runs in React Native without modification.
