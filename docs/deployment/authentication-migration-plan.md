# Authentication and authorization migration plan

**Status:** plan only. No Supabase Auth project or credentials were used.

## Current Laravel behavior

- `Auth::routes()` registers login/logout, forgot/reset password, email verification, and generic registration endpoints.
- `LoginController::username()` accepts either email or username through `identity`; `users.email` and `users.username` are distinct nullable/unique values.
- Laravel stores a bcrypt password in `users.password`, uses a session cookie + CSRF web middleware, and stores password reset tokens in `password_resets`. The custom `NexaSmsPasswordReset` builds branded emails and tenant-aware reset URLs.
- Roles are keyed by `users.user_type` and `roles.name`, with role-to-permission links and per-user allow/deny overrides. `PermissionService` prevents platform permission names being used by school users and vice versa.
- Platform owner (`super_admin`) has no `school_id`; school user has one school. `InitializeTenantContext` derives tenant from host and user, then model global scopes constrain rows. Existing school roles include `admin`, `principal`, `head_teacher`, `teacher`, `accountant`, `bursar`, `librarian`, `parent`, `student`, and `staff`; retain only the current product's intended role list while reconciling legacy data.
- Public generic registration is routed but `RegisterController::create()` only sets name/email/password while the existing users migration also requires non-null code/user_type. It does not assign a tenant. Do not reproduce it as self-serve registration without an explicit onboarding/authorization policy.

## Target mapping

| Laravel concept | Supabase target |
|---|---|
| `users.password`, Laravel guard/provider | `auth.users` credential identity. Never expose service/secret keys to Vercel browser bundle. |
| `users` profile and legacy numeric PK | Profile table retains legacy integer ID plus unique `auth_user_id uuid` reference to `auth.users`; preserve relationships during staged data migration. Profile API exposes only safe columns. |
| email login | Supabase password sign-in with email. |
| username-or-email login | Supabase Auth natively uses email/phone identities, not arbitrary Laravel username semantics. Options: migrate all users to verified email login and map legacy username aliases, or implement a rate-limited Edge Function resolver which maps a supplied username to the linked Auth email and performs ordinary password sign-in without exposing lookup results. Return uniform errors and prevent account enumeration. Users with no verified email need an account recovery/contact workflow. |
| login/logout sessions | Supabase Auth access/refresh token session. Use `@supabase/supabase-js`; persist session appropriately for SPA, refresh safely, and always rely on RLS for data access. For SSR frameworks, use current `@supabase/ssr` cookie patterns. |
| Laravel `password_resets` and reset email | Supabase Auth password recovery and Auth email templates/SMTP; redirect to Vercel's allowlisted recovery route. Discard/reset legacy token table after cutover; never copy active reset tokens. |
| email verification | Supabase Auth confirmation and redirect allowlist. Preserve existing account verification policy and test disabled/verified legacy accounts explicitly. |
| role and permission records | `roles`, `permissions`, `role_permissions`, membership-bound user overrides. Query roles server-side under RLS; role IDs and permission changes cannot be client-controlled. |
| tenant selection by request host | Resolve hostname to school slug/ID, but treat host only as context, not authorization. Confirm authenticated profile has active membership for resolved school before data access; RLS repeats the check. |
| CSRF / session middleware | Supabase bearer JWT calls + Auth client session protections. Remove Laravel CSRF dependency only after all legacy form endpoints are retired; protect cookie-based frontend/API routes if adopted. |
| Laravel `Hash::make` | Do not assume stored bcrypt hashes can be imported unchanged. Check current Supabase Auth import support and exact hash parameters on a copy; if not supported, require password reset/re-enrollment. Never log/export plaintext passwords. |

## Authorization and tenancy requirements

- Authorization source of truth is the database membership and role tables, not `user_metadata`, local storage, URL parameters, request `school_id`, or user-edited claims.
- Use `auth.uid()` to match `users.auth_user_id` and membership; a request-provided school is accepted only if it matches a currently active membership. `INSERT` policies require matching tenant with `WITH CHECK`; `UPDATE` uses both `USING` and `WITH CHECK`; ownership/role fields are not directly writable.
- Enable RLS for every exposed public table. Apply per-operation policies (select/insert/update/delete) and include role/permission predicates where the Laravel route required them. “Authenticated” alone does not grant access.
- Parent policies require an explicit guardian-child relationship. Student self-service should be limited to the student's own identity/records. Teacher access must also preserve assigned class/section/subject rules if present in existing controller logic.
- Platform operations are separate. Use a tightly gated platform owner role/membership; do not grant it through a browser-controlled claim. If privileged Auth user provisioning needs service credentials, isolate them in an Edge Function secret and validate caller authorization there.
- Token claim changes may be stale until refresh. Keep decisive role/membership checks in RLS/database, and revoke/re-authenticate sessions on high-impact account/role changes as required.

## Edge Functions required or conditional

1. **School + first-admin provisioning (required if preserving owner workflow):** privileged Auth user creation plus school/profile/membership bootstrap; make retries idempotent, clean up partial failures, and verify owner permission. A DB transaction alone cannot atomically include Auth Admin API; design reconciliation/compensation.
2. **Username login alias (conditional):** required only if username login must remain. Rate-limit, generic errors, no username lookup endpoint, and return only a standard Auth session.
3. **Staff/student/parent invitation or account provisioning (required if UI creates auth identities):** validate school membership and role grants; do not expose service key.
4. **Sensitive platform administration (conditional):** use Edge Function for operations that need Auth Admin API or privileged multi-step processing; ordinary school CRUD should use RLS/Postgres where possible.
5. **Webhook/third-party mail (conditional):** only if existing SMTP/Auth templates cannot implement a current workflow.

Prefer SQL transactions/RPC for atomic database-only operations (payment ledger and receipt numbering, promotion, attendance batch, academic period consistency). Edge Functions are not the default location for all CRUD. Supabase Edge Functions are Deno TypeScript server-side functions; validate JWT authorization and keep secrets in function secrets. [Edge Functions guide](https://supabase.com/docs/guides/functions).

## Cutover checklist

- [ ] Build account reconciliation report using user ID, school, user_type, email, username and verification status; do not export password values into reports.
- [ ] Decide verified email/username policy, duplicate resolution, and users without email.
- [ ] Test password hash migration support; otherwise schedule secure reset for each user cohort.
- [ ] Create Auth identities and immutable profile link; preserve legacy integer relationship IDs.
- [ ] Map every role and current individual allow/deny override; review role definitions against current product's seven account categories and legacy extras.
- [ ] Test login, logout, expiry/refresh, password recovery, email confirmation, tenant subdomains, school disabled/suspended behavior and account switching.
- [ ] Test each role against every resource/RLS table, direct-ID access, forged school ID, parent/student/teacher cross-school access and platform boundary.
- [ ] Configure Auth SMTP, site URL and exact allowed redirect URLs per environment.
- [ ] Remove Laravel auth routes only after frontend and RLS parity tests are approved.
