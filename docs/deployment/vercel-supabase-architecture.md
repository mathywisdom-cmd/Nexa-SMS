# Nexa SMS Vercel + Supabase target architecture

**Assessment only.** This describes a path to migrate the actual Laravel application. It is not a simplified replacement, a deployment configuration, or an authorization to connect/deploy. No Supabase project, Vercel project, database, or credentials were used.

## Recommended architecture

```text
Browser
  ├─ Vercel CDN: Vue 3 + Vite SPA (public/legal, platform, school, parent/student/teacher screens)
  ├─ Supabase Auth: sign-in, token refresh, verification, password recovery
  ├─ Supabase Data API → PostgreSQL (RLS on every exposed application table)
  ├─ Supabase Storage (RLS object policies; private records, selective public branding)
  └─ Supabase Edge Functions (only privileged Auth provisioning, username alias, webhooks)

PostgreSQL functions/constraints: atomic school-domain operations, payment/receipt,
attendance batches, promotion and academic integrity where a transaction is required.
```

Vercel hosts the frontend only. Laravel is **not** hosted on Vercel or kept as the target backend. Preserve the existing UI by translating Blade layouts/workflows into Vue pages; reuse CSS, images and visual references where compatible. The existing Vue 2 component tree is only the example component, so a Vue 3/Vite SPA is recommended, not a lift-and-shift. Backend controllers/routes must be replaced by Supabase queries/RLS, transactional SQL functions and a small set of Edge Functions.

## Security boundary

- Supabase publishable key may be used in the browser; service-role/secret keys never may.
- Every exposed table gets RLS. Each school-owned select/write validates Auth identity → active membership → requested school and required role/permission. Browser `school_id` is untrusted context.
- RLS checks use relational membership data, not user-editable `user_metadata`. Prevent role/permission/school reassignment from client writes. Use explicit `USING` and `WITH CHECK` for updates.
- Platform owner functions/policies are distinct from school membership. Parent/student/teacher scopes need record-specific relationships, not broad school visibility.
- Storage object policies repeat the tenant/relationship checks; private student documents and receipts use signed URLs after authorization.
- Use transactional functions for workflows that span multiple rows. Edge Functions validate caller authorization and handle operations needing Auth Admin API or third-party secrets; keep them short, idempotent, rate-limited and observable.
- Supabase documentation highlights the difference between Data API grants and RLS; a table needs both the right grant and the right row policy. Views need security-invoker or restricted schema handling. [RLS guide](https://supabase.com/docs/guides/database/postgres/row-level-security), [Edge Functions](https://supabase.com/docs/guides/functions).

## Feature coverage and work size

The backend inventory contains 35 entries spanning auth, platform/schools, tenancy, roles, all school modules, portals, uploads and utilities. The frontend plan maps every route family in `routes/web.php` and the one existing API endpoint. The intent of all observed workflows can be preserved, including platform owner, school roles, school dashboards, attendance, exam/marks, fees and receipts, library, dormitories, timetables, portal access and legal pages. They will need frontend and backend rewrites because Laravel Blade/controller routes are not a Supabase backend.

Existing security behavior must be reimplemented, not assumed: Laravel's model global scope and middleware do not run in a SPA. Tenant isolation, route permissions, parent-child access, teacher assignment, CSRF/session behavior, validation, audit and file privacy must be encoded in RLS, constraints, SQL functions/Edge Functions and frontend UX, then tested against direct table/API access.

## Environment/configuration inventory (names only)

**Vercel frontend:** `VITE_SUPABASE_URL`, `VITE_SUPABASE_PUBLISHABLE_KEY` (or current Supabase publishable/anon client key name), `VITE_PLATFORM_DOMAIN`, `VITE_SCHOOL_BASE_DOMAIN`, `VITE_APP_ENV`, and optionally a public error-reporting DSN. All `VITE_` values are browser-visible; only public values belong there.

**Supabase project settings/secrets:** project database credentials/connection mode managed in Supabase; Auth site URL and allowlisted Vercel production/preview/recovery redirects; SMTP provider config; Edge Function secrets for Auth Admin operations and third-party integrations. Never place service-role/secret key in `VITE_` variables.

No real values were read or included. Connection credentials, mail secrets and service keys are not required for this planning phase.

## Deployment sequence and acceptance gates

1. **Baseline/requirements:** freeze feature parity scope; inspect every route/role/workflow and produce acceptance cases; decide intended roles (including legacy principal/head-teacher/bursar/staff records) and account policy.
2. **Schema design:** prepare new Supabase SQL migrations for all 40 tables and Auth links, tenant memberships, composite FKs, unique/index constraints, audit policies and Storage metadata. Resolve known PostgreSQL defaults/types; use no production data.
3. **Local tooling, separate authorization:** set up Supabase CLI/local dev workflow if later approved; test all SQL migrations and RLS with roles, not service-role-only queries. The current task explicitly avoids Docker/local Postgres; none was used.
4. **Auth and tenant foundation:** build account mapping, login/username policy, school host resolution, role permissions and RLS; test cross-school direct reads and forged writes before porting business screens.
5. **Shared design system and shell:** create Vue 3/Vite Vercel app with platform/school branding, menus, responsive layout, route guards and accessible error/loading states; reuse checked legacy assets.
6. **Feature migration:** implement modules in dependency order: platform/schools; users/students/parents/teachers; classes/sections/subjects and academic years/terms; attendance; exams/grades/marks/results/PIN; fees/payments/receipts; library/dorms/timetables; reports/audit; uploads/portals/legal. Maintain a route-by-route parity checklist.
7. **Storage and mail:** configure/test bucket privacy, file migration manifest, signed URLs, Auth email/SMTP and password recovery redirects.
8. **Data migration rehearsal:** on an isolated non-production target, reconcile legacy records, identity IDs, row counts, FK orphans, balances, tenant ownership, object checksums and rollback plan.
9. **Vercel preview:** deploy only after security gates to an isolated preview project/target database. Verify multi-school host routing, auth cookie/session refresh, direct API/RLS, uploads, PDFs/receipts, email and mobile/table behavior.
10. **Cutover:** require explicit approval, backups, maintenance/freeze strategy, recovery/reset communications, DNS plan, monitoring, access reviews and rollback window. Do not point production users until all gates pass.

## Current blockers / explicit status

- PostgreSQL migration compatibility was previously assessed statically only; Docker/PostgreSQL/PHP were unavailable in that audit environment. No migrations have been tested against actual PostgreSQL.
- Existing identity is Laravel bcrypt plus username-or-email login; Supabase Auth account/hash import and username compatibility require a decision and real test.
- Existing UI is Blade; no application JSON API exists beyond `/api/user`.
- Local file URLs and file sessions do not fit Vercel/static frontend architecture.
- No Supabase schemas/policies/storage buckets or Vercel project were created.
- No login or data tests were run for this architecture.
