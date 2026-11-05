# Nexa SMS Vercel + Supabase Deployment Audit

Audit date: 2026-11-05  
Project: `D:\Projects\lav_sms-master\lav_sms-master`  
Target Supabase project: Nexa SMS, `vyiyveqnsjyxrekbwdeg`  
Scope: original audit plus Stage 1 isolated Vue/Vite foundation. No live service was changed or deployed.

## Repository state

- The project root contains `artisan`, `composer.json`, `package.json`, `routes/`, `app/`, `resources/`, and 35 Laravel migrations.
- Current branch: `deployment/vercel-supabase-poc`.
- The worktree was already dirty before this audit: `.gitignore` is modified, and prior deployment documents plus `supabase/` are untracked. No existing change was overwritten or discarded.
- Latest commit: `4398e54 docs: add Supabase migration architecture plans`.
- A local `.env` exists. Its values were not read or copied into this report.

## Current framework and architecture

The application is still a Laravel 8.42.1 monolith. Its primary user interface is server-rendered Laravel Blade, with Laravel web routes, controllers, middleware, Eloquent models, session authentication, and server-side validation. It has not been migrated to Vue 3/Vite.

`package.json` lists Vue 2.5.7 and Laravel Mix 2.x. The only Vue component found is the default `resources/js/components/ExampleComponent.vue`; there is no Vue application router or converted dashboard/module UI. `webpack.mix.js` compiles `resources/js/app.js` and `resources/sass/app.scss` to `public/js` and `public/css`.

Composer locks Laravel Framework 8.42.1. `config/auth.php` uses Laravel's session guard and Eloquent `App\\User` provider. `config/database.php` defaults to MySQL and contains MySQL, SQLite, and PostgreSQL connections. The application's deployed authentication and data access remain Laravel-based; no Supabase SDK/client integration or `auth_user_id` use was found in application code.

## Frontend entry points and routing

- Laravel's HTTP entry point is `public/index.php`; authenticated and public pages are routed through `routes/web.php` (162 lines) and rendered from `resources/views/**`.
- `routes/api.php` contains only Laravel's `/api/user` endpoint protected by `auth:api`; this is not an API for the existing school workflows.
- Existing controllers perform the application's business workflows. Forms depend on Laravel routes, CSRF, session state, redirects, validation errors, and Blade-generated URLs.
- Static UI assets and much of the current CSS/template styling may be reusable during a future conversion, but the pages themselves cannot be served as a Vercel static SPA without conversion. Preserving appearance requires a route-by-route Blade-to-Vue implementation and visual parity checks.

## Backend and Supabase state

The Laravel backend still owns login, authorization, tenant context, CRUD, reports, receipts, validation, and business rules. **Correction from the project owner:** the existing Nexa SMS Supabase project has the initial schema, Stage 1 authorization, and Stages 2A through 2G applied. None of those migrations were reapplied during this stage. Local proposal files in `supabase/migrations/` must not be interpreted as the live migration history. This stage did not query or reassess the resulting live policies/grants. There is no converted frontend invoking Supabase.

Existing Supabase plans and inventories are under `docs/deployment/`. They describe the intended Vue 3 + Vite frontend and Supabase Auth/Postgres/Storage architecture, but do not constitute an implemented application. The live policies and grants from the already-applied Stages 2A–2G were not audited during this frontend task.

## Database and authentication

- Source of truth remains the Laravel schema and 35 migration files. The proposed Supabase schema describes 40 application tables with forced RLS; compatibility has only been reviewed statically. No PostgreSQL migration validation or end-to-end application query migration was performed in this audit.
- Laravel auth is session-based with an Eloquent user provider and Laravel password recovery/verification. The target Supabase Auth identity bridge and trusted provisioning flow are not implemented in app code.
- `routes/api.php` does not expose the existing feature set as JSON. Controllers, requests, policies/middleware, and query behavior still need explicit replacements using Supabase RLS, SQL functions, and Edge Functions where justified.

## Uploads and persistent services

`config/filesystems.php` defaults to Laravel's local `public` disk rooted at `storage/app/public`, with public URLs under `/storage`. Existing controllers use Laravel `Storage`; payment receipt/PDF handling also uses a local disk. This storage is not durable/shared across Vercel instances. No Supabase Storage client or bucket policy integration exists in application code.

Laravel session and cache defaults are file-backed, and the queue defaults to synchronous processing. A Vercel static frontend removes Laravel sessions, but the new Auth client must persist/refresh Supabase tokens correctly. If any Laravel service remains during a staged migration, shared session/cache and job behavior need explicit design. Existing deployment plans note school subdomain/custom-domain routing, private student files, receipts, and email still require implementation/configuration.

## Build and Vercel readiness

- Laravel retains its Mix/Webpack build (`npm run production`). The isolated `frontend/` has its own Vite entry point, Vite config, SPA rewrite, and successful production bundle.
- Vercel project settings remain unconfigured. Set the Vercel project root to `frontend/`; Laravel's root and Mix build remain separate.
- The current Laravel application cannot be deployed as the requested Vercel frontend in its current form. Serving the existing Laravel HTTP backend from Vercel is outside the approved architecture. A static SPA cannot execute Blade, PHP controllers, Laravel session middleware, or server-side uploads.
- The necessary frontend/backend conversion is incomplete. Existing plans already document Vercel + Supabase architecture, feature inventory, schema design, storage, and auth plans; no module parity implementation was identified.

## What is complete

1. The Laravel source application remains intact, with its full Blade/controller implementation and 35 source migrations.
2. The deployment branch and prior planning/audit documents exist.
3. The existing Nexa SMS Supabase project is reported by the project owner to have initial schema, Stage 1 authorization, and Stages 2A through 2G applied; this stage made no database changes.
4. Static architecture, feature, schema, PostgreSQL compatibility, storage, authentication, and deployment planning documents are present.

## What is incomplete

1. No Vue 3/Vite application or converted UI routes/components exist.
2. Existing Laravel web workflows have not been converted into Supabase-backed frontend flows.
3. Supabase Auth signup/login/recovery, Auth-to-profile provisioning, username compatibility, and role/membership administration are not implemented in the frontend/backend replacement.
4. Application feature integration with the already-applied Supabase authorization/backend is not implemented in the frontend; the current live RLS/grant posture should be reviewed before connecting a client.
5. Existing uploads, receipts, private document access, and image URLs have not been moved to Supabase Storage.
6. PostgreSQL compatibility and runtime queries have not been validated end to end against the actual application's workloads.
7. Vercel routing, SPA fallback, environment-variable setup, domain/subdomain resolution, preview deployment, and production acceptance checks are not configured.

## Deployment blockers

- The UI is still Blade and needs a comprehensive Vue 3 conversion to meet the requested frontend target.
- There is no feature API behind a Vercel frontend. The single Laravel `/api/user` route is insufficient.
- Auth and all tenant/role authorization must be rebuilt and tested. Current no-policy/no-grant state intentionally prevents browser access.
- Source database compatibility has not been exercised in PostgreSQL, and no production migration should proceed until isolated validation is approved and completed.
- Local filesystem storage, Laravel server sessions, Laravel URL generation, and server-side PDF/receipt paths do not directly fit a static Vercel frontend.
- Vercel, Auth redirect, SMTP, Storage, and domain configuration are not complete. No secrets were inspected for this audit.

## Original recommendation (superseded by the Stage 1A update below)

## Stage 1 implementation update — Vue 3 + Vite foundation

Created an isolated frontend in `frontend/`, leaving Laravel Mix and the original PHP application in place. It uses Vue 3, TypeScript, and Vite with a pinned npm lockfile. The initial pages are a platform-branded login preview and a school dashboard shell with the legacy top bar, sidebar, page heading, metric-card grid, payments/activity tables, and calendar area. Empty placeholders replace all data; there are no records, users, or school fixtures. Reusable shell, empty-state, and button components were added. Existing Bootstrap/Limitless/theme/icon CSS and image assets were copied into the frontend's own public asset directory. A Vercel SPA rewrite is configured in `frontend/vercel.json`.

Files created/changed for the frontend stage:

- `frontend/package.json`, `frontend/package-lock.json`, `frontend/tsconfig.json`, `frontend/vite.config.ts`, `frontend/vercel.json`, `frontend/index.html`
- `frontend/src/main.ts`, `frontend/src/App.vue`, `frontend/src/style.css`
- `frontend/src/components/AppShell.vue`, `frontend/src/components/BaseButton.vue`, `frontend/src/components/EmptyState.vue`, `frontend/src/components/FormField.vue`, `frontend/src/components/DataTable.vue`, `frontend/src/components/ModalFrame.vue`, `frontend/src/components/InlineNotice.vue`, `frontend/src/components/LoadingState.vue`
- `frontend/src/pages/LoginPage.vue`, `frontend/src/pages/DashboardPage.vue`
- copied theme CSS, IcoMoon icon files, and existing global images under `frontend/public/`
- `.gitignore` now also excludes `frontend/node_modules/` and `frontend/dist/`

The frontend uses the existing visual assets and dimensions as references, but visual parity has **not** been verified. The preview has static states, no working authentication/forms/data, and no ported feature pages. `vue-tsc --noEmit` passes. `vite build` fails while Vite launches esbuild with Windows `spawn EFTYPE` for `@esbuild/win32-x64/esbuild.exe`. The package install also initially failed at the same native binary check; `npm install --ignore-scripts` completed so TypeScript validation could run. npm reported 6 advisories in the full install (3 moderate, 3 high); `npm audit --omit=dev` reported zero production dependency advisories. Review the development advisories before deployment. Do not claim a successful production build. In Vercel, configure the project root as `frontend/` after the build blocker is resolved.

### Completion status and next stage

The Vue 3/Vite foundation and Vercel rewrite exist, but this stage is **not deployment-ready** until the native esbuild execution issue is resolved, the production build succeeds, and visual comparisons are reviewed at desktop and mobile widths. The Laravel app source and commands were preserved; `.gitignore` was extended only for frontend generated output. Supabase was not queried or modified in this stage, and all stated applied migration status comes from the project owner's correction above.

**Next stage:** resolve the build environment and use screenshots to tune the representative login/dashboard shell for visual parity. After parity and build gates pass, a separate approved Stage 2 can begin Supabase Auth and tenant-aware feature integration. Do not begin feature API migration or production deployment as part of this foundation stage.

After the shell achieves visual parity, proceed to isolated Auth/tenant integration and RLS/API validation, then port feature workflows in dependency order from the existing feature inventory. Only configure Vercel preview after a representative end-to-end workflow, tenant-boundary tests, storage privacy, and PostgreSQL compatibility gates pass. Production deployment remains a later explicit step.

## Audit limits

This audit did not run builds or application tests, alter application functionality, inspect `.env` contents, apply SQL, create data, touch Velocity, configure Vercel, or push to GitHub. The current Supabase state above is based on existing project audit artifacts; no live query was needed for this repository-only audit.

## Stage 1A update — build repair and visual verification status

### Windows Vite build diagnosis

The host is Windows 10 x64, Node.js 24.21.0, npm 11.19.0, Vite 6.3.5, esbuild 0.25.12, and the expected `@esbuild/win32-x64` 0.25.12 package. The earlier interrupted install left the platform package directory with only an executable and no package manifest; its generated lock entry had no version, tarball URL, or integrity metadata. Windows rejected the orphan executable with `EFTYPE`. The environment also sets `NPM_CONFIG_OFFLINE=true` and HTTP(S)_PROXY to unavailable `127.0.0.1:9`, preventing npm from repairing the missing optional package until overridden for the install command.

The exact Windows x64 esbuild package is now pinned as a build-only dev dependency. `allowScripts.esbuild` permits esbuild's own install-time binary check. No broad dependency upgrade or toolchain replacement was made. The binary reports version 0.25.12. Within the restricted sandbox native child-process startup returns `EPERM`; outside that sandbox the binary runs and the production build succeeds.

### Validation results

- `npm exec vue-tsc -- --noEmit`: passed.
- `npm run build`: passed outside the restricted sandbox; Vite transformed 22 modules and emitted `dist/index.html`, CSS, and JS bundles.
- `npm audit`: 6 advisories (3 moderate, 3 high) remain in the full dependency tree: Vue/server-renderer XSS, minimatch denial-of-service through vue-tsc tooling, and Vite dev-server/path handling advisories. `npm audit --omit=dev` reports 0 production advisories. npm recommends major upgrades for automatic remediation; they were not applied because this task forbids blind upgrades.
- Frontend tests: none are defined in package scripts or present in `frontend/`; no frontend test suite ran.
- The local Vite `/login` endpoint returned HTTP 200.

Changed dependency/config files: `frontend/package.json`, `frontend/package-lock.json`; `.gitignore` excludes `frontend/node_modules/` and `frontend/dist/`. Laravel package files, Mix config, routes, and PHP code remain untouched.

### Visual comparison evidence and limits

Inspected original Blade login, login/master layouts, top menu, role-dependent sidebar, header, support-team dashboard, Bootstrap/Limitless styles, `qs.css`, IcoMoon CSS/fonts, and existing images. The Laravel app was not listening on port 8765 and PHP was unavailable, preventing a fresh original screenshot. The Vite server returned 200, but Chrome headless capture and Windows UI capture were blocked by process/UI capture restrictions; no screenshots were produced.

The requested 1440×900 desktop, 768×1024 tablet, and 390×844 mobile visual inspections are **not complete**, so visual parity is **not verified**. Known differences to compare against original screenshots: login uses CSS approximations instead of the original Limitless login behavior; dashboard values/data are static empty placeholders; school/user labels are generic; sidebar entries are representative rather than all role-conditional menus; mobile sidebar behavior is custom and unverified; pages beyond login and the dashboard shell are not ported. Form, table, modal, notice, and loading components are reusable primitives and have not been matched against individual Blade examples.

### Stage 1A completion decision

The Windows build now succeeds outside the restricted sandbox, and TypeScript checks pass. Stage 1A is **not complete** because the requested viewport inspections and visual-parity evidence are missing. The Laravel source and Mix configuration remain intact. The frontend is not ready for feature API migration.

Next, complete screenshot-based desktop, tablet, and mobile comparisons, correct visual discrepancies, and rerun TypeScript/build checks. Only after those gates pass should a separately approved stage begin Supabase Auth and tenant-aware feature integration. No feature API migration or deployment was started here.

## Stage 1A2 — screenshot verification (2026-11-05)

### Runtime and capture setup

- Vue frontend ran locally with Vite at `http://127.0.0.1:5173`.
- Google Chrome and Python Playwright were available. Captures were browser screenshots at device scale factor 1, at the exact requested viewport dimensions.
- The requested PHP runtime was not available: `php` and Composer were not on PATH, and `D:\Temp\codex-php-8.0.30\php.exe` was absent. Although `vendor/autoload.php` is present, Laravel could not be started without PHP. Port 8765 did not provide a running original Laravel reference. No Laravel configuration was changed and no demo account/data was created.
- The Laravel Blade/CSS source was reviewed, including `resources/views/layouts/master.blade.php`, `resources/views/partials/top_menu.blade.php`, `resources/views/partials/menu.blade.php`, `resources/views/partials/header.blade.php`, the login layouts/view and dashboard view, plus copied Limitless/Bootstrap styles and IcoMoon assets. This is source-based comparison only; the six equivalent original screenshots could not be produced.

### Captures

All six requested Vue captures exist under `docs/visual-verification/stage-1a2/`:

- `login-desktop-1440x900.png`
- `login-tablet-768x1024.png`
- `login-mobile-390x844.png`
- `dashboard-desktop-1440x900.png`
- `dashboard-tablet-768x1024.png`
- `dashboard-mobile-390x844.png`

The screenshots were opened and visually inspected. Both pages loaded with their expected assets and typography. The login composition retained the existing dark header, teal geometric background, centered white form card, blue action button and legal footer links. At the three tested sizes, the form and footer remain visible without horizontal overflow. The dashboard preserves the dark teal/charcoal shell, teal active navigation, four colored summary tiles, light metric cards and empty-state panels; it scrolls vertically as expected.

### Difference found and correction

The initial dashboard captures showed the Limitless `.sidebar` rule fixing the sidebar at the viewport origin because the Vue shell omitted the framework's `sidebar-expand-md` responsive class. The fixed sidebar covered the desktop and tablet header and first grid column. The original Laravel menu uses the responsive sidebar expansion pattern. The Vue `<aside>` now has `sidebar-expand-md`, and its local shell styles explicitly keep the top bar and content stacking predictable. The dashboard was recaptured after this correction.

Post-correction browser measurements at scroll position `(0,0)`:

| Viewport | Sidebar rectangle | Main content rectangle | Document size | Horizontal overflow |
|---|---:|---:|---:|---|
| Desktop 1440×900 | x=0, y=52, w=273 | x=273, y=52, w=1167 | 1440×1180 | None |
| Tablet 768×1024 | x=0, y=52, w=273 | x=273, y=52, w=495 | 768×1543 | None |
| Mobile 390×844 | hidden until menu toggle | x=0, y=52, w=390 | 390×2283 | None |

At 768px the dashboard uses two columns without the sidebar covering content; at 390px the sidebar is hidden and the cards stack in one column. The pages have normal vertical scrolling. No broken icon/font/image assets were observed in the captures.

### Validation and remaining limits

- `npm exec vue-tsc -- --noEmit`: passed.
- `npm run build`: passed; Vite 6.3.5 transformed 22 modules and emitted the production HTML/CSS/JS bundle.
- Local HTTP checks: `/login` and `/dashboard` returned HTTP 200 from the Vite server.
- Frontend automated tests: none are defined, so no test suite ran.
- No original Laravel screenshot comparison was possible. Exact pixel/layout parity with the running Laravel app is therefore unverified. The dashboard preview is intentionally empty/static; its sample labels and empty states are not a substitute for authenticated role-specific navigation or real school data. Pages other than the representative login and dashboard shell remain unported.
- No Supabase/database, PHP/Laravel source, migrations, or Laravel Mix files were changed. The UI-only changes are `frontend/src/style.css` and `frontend/src/components/AppShell.vue`; six screenshot artifacts were added/updated in the directory above.

### Stage decision

The specific responsive sidebar overlap found in the Vue dashboard was corrected and verified in screenshots. The requested Vue captures and builds pass, but **Stage 1A cannot be declared complete** because the original Laravel app could not be run and corresponding original screenshots were unavailable, so parity cannot be established. Before feature API migration, make the existing PHP 8 runtime available without altering project configuration, start Laravel using its already-configured local environment, capture the same six reference views, compare them with these artifacts, and resolve any remaining differences. Do not begin feature API migration until that comparison is complete.
