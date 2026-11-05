# Deployment blockers and readiness gates

Audit date: 2026-11-04  
Branch: `deployment/vercel-supabase-poc`  
This is a gate list, not a deployment configuration. Nothing was deployed or connected.

## Verified working

- Git root is the Laravel project root and contains `artisan`, `composer.json`, application files, and 35 migration files.
- Current branch is `deployment/vercel-supabase-poc`; baseline commit `1f278ee774b0946dad1d5f1f548702feea3b8b57` is an ancestor of HEAD.
- Working tree was clean at audit start.
- Static code review confirms Laravel 8.42.1 has a configured PostgreSQL connection and uses framework PDO/query/schema abstractions.
- No production or staging database was contacted; no Supabase or Vercel service was accessed.

## Incompatible / likely blockers

1. **Migration defaults:** `2018_10_06_225007_create_pins_table.php` defines `pins.used` and `pins.times_used` as strings with numeric `0` defaults; `2018_10_31_192540_create_book_requests_table.php` defines `book_requests.returned` the same way. PostgreSQL may reject generated varchar defaults. This is a static high-confidence risk, not a captured runtime error. Prove on disposable PostgreSQL and then correct via an authorized safe migration strategy.
2. **PDO prepared statements vs Supabase transaction pooler:** Laravel 8.42.1 Pg connector inherits `PDO::ATTR_EMULATE_PREPARES=false`; Supabase shared transaction pooler disallows prepared statements/session state. Choose a compatible endpoint/mode or configure and prove driver compatibility before production. Do not assume the pooler is interchangeable with direct PostgreSQL.
3. **Durable storage:** local public uploads and `public/storage` URLs do not work as persistent multi-instance storage on Vercel containers. Supabase Storage/object-store integration and URL/auth handling require code/config changes.
4. **Shared sessions/cache:** defaults are file-based and local to each container. Shared storage is needed for login continuity and consistent cache/locks. The project has no `sessions`, `cache`, or `jobs` migrations.
5. **Container/PHP runtime:** no image, HTTP server, `$PORT` handling, extension set, graceful shutdown, or Vercel runtime was built/tested. Vercel container Functions are not an always-on server with durable disk.
6. **PostgreSQL SSL:** `config/database.php` hard-codes `sslmode=prefer`; hosted TLS policy should be explicit/configurable and tested.

## Requires external credentials/services

- Supabase project/database URL and credentials, storage bucket/credentials, TLS/connection mode.
- Vercel account, project, container runtime availability/plan, environment variables, deployment limits and billing confirmation.
- Domain ownership, DNS access, wildcard certificate setup, and optional custom-domain policy.
- SMTP provider credentials and production email deliverability verification.
- Shared Redis/cache provider if selected.

Secrets must be stored in provider environment settings, not committed, written to image layers, or included in audit output.

## Requires code/build changes

- Make PostgreSQL `sslmode` environment-configurable.
- Resolve and regression-test the numeric defaults on string columns without rewriting migration history after deployment.
- Provide PHP `pdo_pgsql` and the application's locked-package extensions; create a reproducible Composer/Node build.
- Add a supported Vercel container HTTP server entry configuration and test Laravel routing/public assets.
- Externalize files and replace local `asset('storage/...')` assumptions with disk-aware public or signed URLs while preserving private document access.
- Select a shared session and cache strategy; provision tables/services as needed.
- Configure stderr/external logging and writable ephemeral Laravel scratch paths.
- Validate school subdomain host parsing, tenant scope, wildcard DNS/TLS, secure cookie domain, and custom-domain support separately.
- Decide if receipt PDFs are generated synchronously; confirm Vercel duration/memory/payload limits for realistic PDFs and uploads.

## Local verification unavailable

Commands `docker`, `psql`, `pg_ctl`, `postgres`, `initdb`, `php`, and `composer` were unavailable in the audit environment. No Docker image, PHP build, PHPUnit suite, or PostgreSQL migration was run. No PostgreSQL service was found. **PostgreSQL compatibility is unproven.** Do not use SQLite success as a substitute.

## Recommended phase order

1. Restore/provision a local disposable PostgreSQL environment and PHP `pdo_pgsql`; apply the entire 35-migration sequence, capture failures, and add PG integration tests for core authentication, tenant scoping, JSON audit queries, search/date filters, and pagination.
2. Resolve migration compatibility using forward-safe migrations and confirm clean-install plus upgrade paths from a representative legacy schema/data snapshot.
3. Select Supabase direct/session/transaction pooler based on PDO prepared-statement support, connection count, SSL and session state; test the exact endpoint with the Laravel app.
4. Design shared session/cache and object storage, then update code/config in a separately authorized implementation phase and verify file privacy/tenant boundaries.
5. Build and run a local Vercel-compatible container with production assets, `$PORT`, health/startup/shutdown behavior, logs and writable temp paths.
6. Deploy only to an isolated Vercel preview and non-production Supabase project, run migration and multi-instance smoke tests, then review cost, domain, backup, rollback, logging, and secrets before production approval.

## Audit artifacts

- `docs/deployment/postgresql-compatibility-audit.md`
- `docs/deployment/vercel-container-compatibility-audit.md`
- `docs/deployment/persistent-services-audit.md`
- `docs/deployment/deployment-blockers.md`
