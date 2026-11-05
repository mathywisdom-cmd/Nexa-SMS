# Vercel container compatibility audit

Audit date: 2026-11-04  
Repository branch: `deployment/vercel-supabase-poc`  
Scope: architecture review only; no deployment configuration or Dockerfile was created.

## Summary

**Status: possible as a Vercel container Function proof of concept, not verified and not a persistent always-on Laravel host.** Vercel's current Dockerfile/container Functions can package a custom runtime and serve HTTP on the injected `$PORT`. These containers are part of Vercel Functions: they scale per invocation and have function lifecycle/resource limits. They should not be treated as a VM with a guaranteed resident PHP daemon or durable disk. The archived `vercel-community/php` runtime is not part of the recommended architecture.

Vercel announced Dockerfile-based container Functions and describes OCI container images as Functions. Current docs distinguish a container-backed Function from an always-on backend service. Verify current beta availability, runtime support, account limits, and feature flags in the target Vercel account before implementation. [Vercel Dockerfile announcement](https://vercel.com/changelog/bring-your-dockerfile-to-vercel-functions), [Vercel container overview](https://vercel.com/blog/dockerfile-on-vercel), [Vercel Dockerfile deployment notes](https://vercel.com/changelog/deploy-a-container-from-a-plain-dockerfile).

## Application fit

| Area | Finding | Status |
|---|---|---|
| Laravel request entry | Laravel's normal web entry is `public/index.php`; a PHP HTTP server can route requests there and serve public assets. A container needs a foreground server bound to `0.0.0.0:$PORT`, correct path-info/rewrite behavior, and tested graceful termination. | **Potentially compatible; not tested.** |
| PHP HTTP server | FrankenPHP is a plausible single-process HTTP server for the image. Laravel Octane/FrankenPHP is not currently declared in `composer.json`; adding it would be an implementation decision. Traditional nginx+PHP-FPM requires a foreground supervisor/process arrangement and has more lifecycle complexity. | **Requires architecture choice and code/build changes.** |
| PHP and extensions | `composer.json` permits PHP `^7.2||^8.0`; lock pins Laravel 8.42.1. A container must install Composer dependencies and `pdo_pgsql`, plus Laravel/PDF/image-needed extensions. At minimum validate `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `curl`, `bcmath`, PDO, and `pdo_pgsql`; confirm GD/Imagick and DomPDF requirements from the locked packages and app usage. | **Requires build work; runtime not built.** |
| Composer | `composer install --no-dev --prefer-dist --optimize-autoloader` belongs in image build; secrets must not be build args/layers. Composer is unavailable locally in this audit environment. | **Untested.** |
| Frontend assets | `webpack.mix.js` builds JS and Sass via Laravel Mix. A Node build stage should run the lockfile-backed production build and copy artifacts into the PHP image. No `package-lock.json` was found in the reviewed root state, making deterministic npm installs a blocker to resolve. Existing assets in `public/` do not prove a clean build. | **Requires reproducible asset build.** |
| Writable paths | Laravel writes compiled views, caches, sessions, logs, and temp files under `storage`/`bootstrap/cache`; container filesystem is not durable between instances. Point scratch paths at writable ephemeral storage such as `/tmp`, use shared session/cache, and send logs to stderr. Uploaded files cannot remain on the container disk. | **Requires configuration/code changes.** |
| Runtime lifecycle | Vercel Functions are request/invocation-backed and can scale to zero; a long-running Octane worker's reuse and shutdown model must be tested. Do not rely on local process memory for session state, locks, scheduled work, or data. | **Potential mismatch; proof required.** |
| Limits | Vercel function/container memory, duration, request payload, image size, concurrency, and pricing depend on runtime, compute mode, and account plan. Standard Vercel Function docs list a 4.5 MB request/response payload limitation in relevant runtimes, but current container-specific limits need confirmation against the actual account and feature tier. Large student uploads and PDF generation are risk areas. | **External/account-specific prerequisite.** |
| Logging/errors | Existing Laravel file logging (`storage/logs/laravel.log`) is ephemeral. Production must disable debug output and route logs to stdout/stderr or an external sink, while preserving useful exception reporting. | **Requires configuration.** |

Vercel documents container Functions as subject to function constraints and with no persistent filesystem; the container is not a persistent disk/server. Limits vary, so validate the exact deployed runtime/account rather than carrying over limits from another framework. [Vercel container vs. server guidance](https://vercel.com/kb/guide/docker-on-vercel-vs-render), [Function limitations](https://vercel.com/docs/functions/limitations). Vercel has separately described Daemons/Services for longer-lived backend processes; this project does not need a queue worker today, and those offerings are distinct from a web request Function. [Vercel backend containers and daemons](https://vercel.com/blog/backends-on-vercel-python-containers-and-daemons).

## Recommended architecture to prove (not implemented)

1. Use Vercel's supported Dockerfile/container Function path, not `vercel-community/php`.
2. Build a minimal OCI image using a supported PHP base and FrankenPHP (or another documented HTTP server) that listens on `$PORT`; direct web requests to Laravel's `public/index.php` and keep static assets under `public/`.
3. Use a multi-stage build for Composer and Node assets. Pin dependencies with lockfiles, build assets in production mode, and omit development dependencies and secret files.
4. Set production environment values through Vercel's encrypted project environment settings. Keep `APP_DEBUG=false`, a stable `APP_KEY`, and the existing platform URL; do not bake `.env` into the image.
5. Externalize PostgreSQL, uploads, sessions, cache, and logs before scaling to multiple instances. Set Laravel's writable cache/view/temp paths to ephemeral writable directories and treat them as disposable.
6. Run schema migrations as a controlled release task against a disposable Supabase project first; never migrate in an HTTP startup path.
7. Validate login/session/CSRF across multiple instances, subdomain routing, upload/download, receipt PDF generation, email, cold starts, graceful shutdown, payload/duration limits, and per-plan cost before calling the POC viable.

## Not verified

- No Dockerfile, image, Vercel project, Vercel config, or deployment was created.
- No local Docker executable was available; the image could not be built or run.
- No Vercel account/plan/runtime feature status was queried.
- No application HTTP, upload, PDF, or concurrency tests ran in a container.
