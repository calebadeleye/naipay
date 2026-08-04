# Roles and permissions

Naipay authorises every action against a **permission**, never against a role name.
Roles are a convenience the business reorganises; permissions are the actual control.
A policy that asks "is this user a Branch Manager?" silently breaks the day someone
invents a new role that should also have been allowed.

Source of truth:

- Permissions — `app/Domains/Identity/Enums/Permission.php`
- Roles — `app/Domains/Identity/Enums/Role.php`
- Grants — `app/Domains/Identity/Support/RolePermissionMatrix.php`

The seeder syncs grants on every deploy, so a permission removed from the matrix is
actually revoked rather than left behind. Access never accumulates silently.

---

## Design principles

**Segregation of duties.** No role holds both sides of a financial control:

| Control | Maker | Checker |
| --- | --- | --- |
| Repayment | Cashier / Loan Officer records | Finance Officer verifies, Finance Manager approves |
| Loan credit | Credit Officer assesses and recommends | Credit Manager approves |
| Loan money | Credit Manager approves the credit | Finance Manager releases the funds |
| Reversal | Finance Officer raises | Finance Manager approves |

This is what makes maker-checker meaningful. Enforcing "not the same person" is worth
little if one role can perform every step anyway.

**Least privilege.** Unmasked BVN and NIN (`merchants.view_sensitive`) are held by three
roles only — Super Administrator, Credit Manager and Compliance Officer — because
verifying identity documents and assessing credit are the only jobs that need them.

**The audit trail is writable by nobody.** There is no `audit.create`, `audit.update` or
`audit.delete` permission anywhere in the system. The trail is append-only by
construction, not by policy.

**Access control is Super Administrator only.** `roles.manage`, `staff.assign_roles` and
`settings.manage` are held by no other role.

---

## Mandatory two-factor authentication

Two-factor cannot be disabled by an account that holds a privileged permission — one
that moves money, changes access, or exposes identity data. The requirement is evaluated
from the permissions actually held, not only from a list of role names, so a bespoke
role granting `ledger.post` is covered without anyone remembering to add it.

Roles where it is always mandatory:

Super Administrator · Executive · Operations Manager · Credit Manager ·
Finance Manager · Compliance Officer · Auditor

---

## Re-authentication at the point of action

Some operations require the actor to re-enter their password or a 2FA code even with an
active session, because an unattended workstation would otherwise be enough to move
money. Configured in `naipay.security.reauthentication_required_operations`:

`loan.disburse` · `repayment.reverse` · `ledger.manual_credit` · `ledger.manual_debit` ·
`loan.write_off` · `bank_account.change` · `staff.role_change` · `settings.manage`

---

## The matrix

● granted · · not granted

| Key | Role | Key | Role |
|---|---|---|---|
| SA | Super Administrator | CSH | Cashier |
| EXE | Executive | COL | Collections Officer |
| OPS | Operations Manager | CMP | Compliance Officer |
| BRM | Branch Manager | SUP | Customer Support |
| CRM | Credit Manager | AUD | Auditor |
| CRO | Credit Officer | RO | Read-only User |
| LNO | Loan Officer | | |
| FIM | Finance Manager | | |
| FIO | Finance Officer | | |

### Dashboard

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `dashboard.view` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |

### Merchants

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `merchants.view` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| `merchants.create` | ● | · | ● | ● | · | · | ● | · | · | · | · | · | · | · | · |
| `merchants.update` | ● | · | ● | ● | · | · | ● | · | · | · | · | · | · | · | · |
| `merchants.approve` | ● | · | ● | ● | · | · | · | · | · | · | · | · | · | · | · |
| `merchants.suspend` | ● | · | ● | · | · | · | · | · | · | · | · | ● | · | · | · |
| `merchants.close` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |
| `merchants.view_sensitive` | ● | · | · | · | ● | · | · | · | · | · | · | ● | · | · | · |

### Businesses

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `businesses.view` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| `businesses.create` | ● | · | ● | ● | · | · | ● | · | · | · | · | · | · | · | · |
| `businesses.update` | ● | · | ● | ● | · | · | ● | · | · | · | · | · | · | · | · |
| `businesses.approve` | ● | · | ● | ● | · | · | · | · | · | · | · | · | · | · | · |

### Categories

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `categories.view` | ● | ● | ● | ● | ● | ● | ● | · | · | · | · | ● | ● | ● | · |
| `categories.manage` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |

### Kyc

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `kyc.view` | ● | · | ● | ● | ● | ● | ● | · | · | · | · | ● | · | ● | · |
| `kyc.verify` | ● | · | · | · | · | · | · | · | · | · | · | ● | · | · | · |
| `kyc.reject` | ● | · | · | · | · | · | · | · | · | · | · | ● | · | · | · |

### Documents

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `documents.view` | ● | · | ● | ● | ● | ● | ● | · | · | · | · | ● | ● | ● | · |
| `documents.upload` | ● | · | ● | ● | · | ● | ● | · | · | · | · | ● | · | · | · |
| `documents.download` | ● | · | ● | ● | ● | ● | ● | · | · | · | · | ● | · | ● | · |
| `documents.verify` | ● | · | · | · | · | · | · | · | · | · | · | ● | · | · | · |
| `documents.delete` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |

### Loan Products

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `loan_products.view` | ● | ● | ● | ● | ● | ● | ● | ● | · | · | · | · | ● | ● | · |
| `loan_products.manage` | ● | · | · | · | ● | · | · | · | · | · | · | · | · | · | · |

### Loan Applications

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `loan_applications.view` | ● | ● | ● | ● | ● | ● | ● | ● | · | · | · | ● | ● | ● | · |
| `loan_applications.create` | ● | · | ● | ● | · | ● | ● | · | · | · | · | · | · | · | · |
| `loan_applications.update` | ● | · | ● | ● | · | ● | ● | · | · | · | · | · | · | · | · |
| `loan_applications.assess` | ● | · | · | · | ● | ● | · | · | · | · | · | · | · | · | · |
| `loan_applications.recommend` | ● | · | · | ● | ● | ● | · | · | · | · | · | · | · | · | · |
| `loan_applications.approve` | ● | · | · | · | ● | · | · | · | · | · | · | · | · | · | · |
| `loan_applications.reject` | ● | · | · | · | ● | · | · | · | · | · | · | · | · | · | · |

### Loans

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `loans.view` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| `loans.create` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |
| `loans.approve` | ● | · | · | · | ● | · | · | · | · | · | · | · | · | · | · |
| `loans.disburse` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |
| `loans.restructure` | ● | · | · | · | ● | · | · | · | · | · | · | · | · | · | · |
| `loans.write_off` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |
| `loans.close` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |

### Repayments

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `repayments.view` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| `repayments.record` | ● | · | · | · | · | · | ● | · | ● | ● | ● | · | · | · | · |
| `repayments.verify` | ● | · | · | · | · | · | · | ● | ● | · | · | · | · | · | · |
| `repayments.approve` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |
| `repayments.reverse` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |

### Collections

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `collections.view` | ● | ● | ● | ● | ● | ● | ● | ● | · | · | ● | · | · | ● | · |
| `collections.manage` | ● | · | ● | ● | · | · | · | · | · | · | ● | · | · | · | · |

### Ledger

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `ledger.view` | ● | ● | · | · | · | · | · | ● | ● | · | · | · | · | ● | · |
| `ledger.post` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |
| `ledger.reverse` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |
| `ledger.manage_accounts` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |
| `ledger.manage_periods` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |

### Bank Accounts

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `bank_accounts.view` | ● | ● | · | · | · | · | · | ● | ● | · | · | · | · | ● | · |
| `bank_accounts.manage` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |
| `bank_accounts.approve` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |

### Reconciliation

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `reconciliation.view` | ● | ● | · | · | · | · | · | ● | ● | · | · | · | · | ● | · |
| `reconciliation.match` | ● | · | · | · | · | · | · | ● | ● | · | · | · | · | · | · |
| `reconciliation.approve` | ● | · | · | · | · | · | · | ● | · | · | · | · | · | · | · |

### Reports

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `reports.view` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| `reports.export` | ● | ● | ● | ● | ● | · | · | ● | ● | · | · | ● | · | ● | · |
| `reports.financial` | ● | ● | · | · | · | · | · | ● | ● | · | · | · | · | ● | · |

### Branches

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `branches.view` | ● | ● | ● | ● | ● | · | · | ● | · | · | · | · | · | ● | · |
| `branches.manage` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |

### Staff

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `staff.view` | ● | ● | ● | ● | · | · | · | · | · | · | · | · | · | ● | · |
| `staff.create` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |
| `staff.update` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |
| `staff.disable` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |
| `staff.assign_roles` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |
| `staff.set_approval_limit` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |

### Roles

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `roles.view` | ● | · | · | · | · | · | · | · | · | · | · | · | · | ● | · |
| `roles.manage` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |

### Approvals

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `approvals.view` | ● | ● | ● | ● | ● | · | · | ● | · | · | · | ● | · | ● | · |
| `approvals.act` | ● | · | ● | ● | ● | · | · | ● | · | · | · | · | · | · | · |

### Notifications

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `notifications.view` | ● | · | ● | · | · | · | · | · | · | · | ● | · | ● | · | · |
| `notifications.send` | ● | · | ● | · | · | · | · | · | · | · | ● | · | · | · | · |

### Audit

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `audit.view` | ● | ● | · | · | · | · | · | ● | · | · | · | ● | · | ● | · |
| `audit.export` | ● | · | · | · | · | · | · | · | · | · | · | · | · | ● | · |

### Settings

| Permission | SA | EXE | OPS | BRM | CRM | CRO | LNO | FIM | FIO | CSH | COL | CMP | SUP | AUD | RO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `settings.view` | ● | ● | · | · | · | · | · | · | · | · | · | · | · | ● | · |
| `settings.manage` | ● | · | · | · | · | · | · | · | · | · | · | · | · | · | · |

