# mefs-api

Laravel API for [`mefs`](../mefs), a pre-order kitchen. See [CLAUDE.md](CLAUDE.md) for the
rules that bite and the order-cycle model; the frontend repo holds the shared parameter
table and the departures from the build brief.

## Setup

Requires PHP 8.3+, Composer and PostgreSQL 18.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the role and both databases (the suite runs against `mefs_testing`):

```sql
CREATE ROLE mefs LOGIN PASSWORD 'mefs_dev_local';
CREATE DATABASE mefs         OWNER mefs ENCODING 'UTF8';
CREATE DATABASE mefs_testing OWNER mefs ENCODING 'UTF8';
```

If Postgres warns `setting an MD5-encrypted password`, that role will fail to authenticate
against a `scram-sha-256` line in `pg_hba.conf`. Re-set it:

```sql
SET password_encryption = 'scram-sha-256';
ALTER ROLE mefs PASSWORD 'mefs_dev_local';
```

Then:

```bash
php artisan migrate
php artisan serve      # http://localhost:8000
```

## Commands

```bash
php artisan test              # against PostgreSQL, never SQLite (brief §11.2)
php artisan test --filter=X
php artisan migrate:fresh --seed
vendor/bin/pint               # format (--test to check)
```

## Verifying the Phase 0 gate

```bash
curl http://localhost:8000/api/v1/health
```

```json
{
  "success": true,
  "message": "Healthy",
  "data": {
    "status": "ok",
    "app": "mefs",
    "environment": "local",
    "time": "…",
    "checks": { "database": { "ok": true, "driver": "pgsql" } }
  }
}
```

Failure paths are enveloped identically — `/api/v1/nope` returns a 404 envelope, and an
unauthenticated `/api/v1/me` returns a 401 envelope. The frontend reads `message` and
`errors` straight off the body and has nowhere else to look.
