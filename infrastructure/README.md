# Infrastructure

Deployment assets for Naipay on the NAI TALK server infrastructure.

| Directory | Contents |
| --- | --- |
| `deployment/` | Deploy scripts, migration and rollback procedures, backup jobs |
| `apache/` | Virtual host configuration for `everymerchant.naitalk.com` |
| `nginx/` | Reverse proxy configuration, as an alternative to Apache |
| `docker/` | Container definitions for local environment parity |

## Deploying a release

Each host is a separate ISPConfig user and runs the same script:

```
# on everymerchant-deploy (naitalk2loan) — api + admin
bash infrastructure/deployment/deploy.sh all

# on everymerchant-portal-deploy (naitalk2portal) — portal
bash infrastructure/deployment/deploy.sh all
```

`deploy.sh` reads paths and pm2 process names from `infrastructure/deployment/deploy.env`
(gitignored, per-host — copy `deploy.env.example` and fill it in once per host). It pulls
`origin/main`, then for the backend runs `composer install --no-dev`, `migrate --force` and
rebuilds the config/route/event caches; for each web app runs `npm ci && npm run build` and
`pm2 restart`. A component whose directory is not configured is skipped under `all` and is a
hard error when named explicitly (`deploy.sh api` / `admin` / `portal`).

The target environment is ISPConfig-compatible:

```
/web/
├── frontend/   Next.js production build
├── backend/    Laravel application
├── storage/    Documents, receipts, statements
└── logs/       Application and access logs
```

Never exposed publicly: `.env`, storage directories, source maps containing secrets,
database dumps, internal logs and private documents.
