# Persistent services audit

Audit date: 2026-11-04  
Scope: describe current app dependencies and external state needed for multi-instance/container deployment. No external services were contacted.

## Service inventory

| Service | Current project behavior | Deployment requirement / status |
|---|---|---|
| PostgreSQL | `config/database.php` includes `pgsql`; MySQL is still default. PostgreSQL TLS is hard-coded as `sslmode=prefer`. Laravel 8 uses PDO and its Pg connector. | **Requires code/config change and PG compatibility test.** Add configurable SSL mode and ensure `pdo_pgsql`; migration compatibility is unverified. Supabase's direct connection suits persistent app servers; shared transaction pooler is intended for serverless but doesn't support prepared statements/session state. Laravel 8's Pg connector inherits `PDO::ATTR_EMULATE_PREPARES=false`, so transaction-pooler compatibility must be proven or a different pooler/connection path chosen. [Supabase connection modes](https://supabase.com/docs/guides/database/connecting-to-postgres). |
| Object/file storage | `config/filesystems.php` defaults to Laravel's `public` disk at `storage/app/public`; an S3 disk is present. Upload paths are rendered through `/storage/...` URLs and local `public/storage` symlink conventions. Files include school/student/user images and documents. Receipt PDFs are generated for download and use local temporary/storage paths in current flows. | **Requires code/config changes and storage migration plan.** Vercel local filesystem is not durable/shared. Supabase Storage exposes an S3-compatible API, but project code must configure endpoint/region/credentials, bucket/visibility, and URL generation; current hard-coded `asset('storage/...')` behavior will not automatically become S3 URLs. Validate upload size, private/student-document access, signed URLs, delete behavior, and PDF temporary paths. S3 compatibility is a subset; do not assume full AWS behavior. [Supabase Storage S3 compatibility](https://supabase.com/docs/guides/storage/s3/compatibility). |
| Sessions | `config/session.php` defaults to `file`, path `storage/framework/sessions`. Cookie domain is env-driven, path `/`, SameSite `lax`. | **Requires shared durable session store before multiple instances.** File sessions will disappear or diverge across function instances. Use Redis or a PostgreSQL-backed session store after provisioning its table; no session table migration currently exists. Set secure cookie, domain, and SameSite intentionally for production and school subdomains; test CSRF/session affinity across instances. |
| Cache | `config/cache.php` defaults to file cache under `storage/framework/cache/data`; database cache uses table `cache` but no migration is present. Redis config expects Predis. | **Requires shared cache or safely disable/cache choice.** File cache is per-instance/ephemeral. Redis is the likely shared cache/lock service; alternatively provision a DB cache table and validate load/locking. Don't assume Supabase database is a drop-in Redis. |
| Queue/background jobs | `config/queue.php` defaults to `sync`; no app classes implementing `ShouldQueue` or queue dispatch usage were found in static search. Database queue expects `jobs`, but no jobs migration exists. Console scheduler only registers Laravel's `inspire` sample command; no scheduled production tasks identified. | **No queue worker currently required by observed code.** If async work is introduced, provision a durable queue and separate worker/daemon; Vercel HTTP request containers are not a reliable worker. Database/SQS/Redis alternatives require config, schema, credentials, and operations. |
| Email | `config/mail.php` is environment-configured; local history uses Gmail SMTP. Password reset and notification mail use Laravel mail. | **External SMTP provider and secrets required.** Configure host, port, TLS, username, password, from address/name in encrypted environment settings; test authenticated password reset from deployed host. Avoid printing secrets. |
| School subdomains | `app/Support/SchoolHostResolver.php` and `app/Http/Middleware/InitializeTenantContext.php` resolve school slug using configured `TENANT_BASE_DOMAIN` and local-domain support. Schools have slugs; no verified per-school custom-domain ownership mapping was identified. | **Requires DNS/domain setup and host tests.** Configure wildcard DNS and wildcard TLS for a shared school domain; Vercel wildcard cert provisioning has DNS requirements. Per-school custom domains need domain verification, routing/lookup and tenant security tests; do not infer support from slug subdomains. [Vercel domain SSL](https://vercel.com/docs/domains/working-with-ssl), [wildcard domain setup](https://vercel.com/docs/domains/working-with-domains/add-a-domain). |
| Logs/temp | `config/logging.php` defaults to stack/single-file Laravel logs. Local temp/compiled views and cache use writable project paths. | **Requires production logging and temp configuration.** Send logs to stderr/external observability; relocate scratch data to ephemeral writable storage and never rely on it across invocations. |

## File storage call sites to revisit in a later implementation phase

Static search found local-storage dependencies in user/staff/student photo/document flows and payment receipts. Important patterns include Laravel `storeAs`, `Storage::exists`, `Storage::deleteDirectory`, and direct `asset('storage/'.$path)` URL construction. Preserve existing authorization and tenant checks when adding remote storage. Do not make the storage bucket public just to preserve old URL construction; student records/documents may need private access and signed URLs.

## Required state model

For a Vercel multi-instance deployment, treat the container filesystem as scratch only. Durable/shared state must live outside it:

- Supabase PostgreSQL for relational records after migration and query testing.
- Supabase Storage S3-compatible API or another object store for uploads and receipt artifacts.
- Redis-compatible shared service or explicitly provisioned PostgreSQL tables for sessions/cache/locks.
- External SMTP provider for email.
- A separately operated queue worker only if async jobs are added.
- Vercel/domain DNS and TLS for the platform host and wildcard school host; custom-domain support requires additional application work.

## External prerequisites not met

No Supabase credentials/project, storage bucket, Redis service, SMTP validation, Vercel project/plan, domain ownership, or DNS access was used or checked in this audit. No data was moved and no external endpoint contacted.
