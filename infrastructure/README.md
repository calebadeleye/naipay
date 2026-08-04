# Infrastructure

Deployment assets for Naipay on the NAI TALK server infrastructure.

| Directory | Contents |
| --- | --- |
| `deployment/` | Deploy scripts, migration and rollback procedures, backup jobs |
| `apache/` | Virtual host configuration for `naipay.naitalk.com` |
| `nginx/` | Reverse proxy configuration, as an alternative to Apache |
| `docker/` | Container definitions for local environment parity |

Populated during Phase 19 (Deployment). The target environment is ISPConfig-compatible:

```
/web/
├── frontend/   Next.js production build
├── backend/    Laravel application
├── storage/    Documents, receipts, statements
└── logs/       Application and access logs
```

Never exposed publicly: `.env`, storage directories, source maps containing secrets,
database dumps, internal logs and private documents.
