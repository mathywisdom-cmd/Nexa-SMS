# Nexa SMS Stage 1 authentication deployment security review

**Review date:** 2026-11-05
**Project:** `vyiyveqnsjyxrekbwdeg`
**Scope:** Local authentication migration proposal, dependent Edge Functions, frontend auth call sites, Laravel legacy-password compatibility, and deployment configuration.
**Review type:** Static source review only. No local tests or hosted writes were performed.

## Decision

**NOT APPROVED FOR DEPLOYMENT.** The authentication migration remains marked REJECTED and is not in the hosted migration ledger. The Edge Functions depend on its RPCs and private tables, so they cannot be deployed as a functioning authentication package before the SQL has passed isolated PostgreSQL/Auth validation, a fresh independent review, and explicit project-owner approval for application.

## Hosted state checked read-only

- The existing Nexa SMS project is `ACTIVE_HEALTHY` on PostgreSQL 17.11.
- Hosted migration history contains the nine schema/authorization migrations documented in `migration-history-reconciliation.md`; the local canonical migration files match that history.
- No Edge Functions are deployed.
- The hosted auth migration proposal and its provisioning functions are absent. Existing catalog observations found no linked Auth users, profiles, or schools.
- Hosted public-signup configuration could not be read through the available project metadata tools. The local `auth.enable_signup = false` setting is not evidence of hosted configuration.

## Confirmed protections in source

- Provisioning SQL RPCs revoke execution from `PUBLIC`, `anon`, and `authenticated`; the privileged worker functions are granted to `service_role`. The three profile/school writes are `SECURITY INVOKER`, with actor/profile/permission checks in SQL and tenant ownership derived from the administrator profile.
- `get_my_auth_context()` is `SECURITY DEFINER`, uses a fixed empty `search_path`, has `row_security = off`, is executable only by `authenticated`, and resolves the profile only through `users.auth_user_id = auth.uid()`. It returns no context for an unlinked account or a school outside `active`/`trial` status.
- The owner profile function uses an advisory lock and the unique partial owner index. School and staff provisioning reject unauthorized roles and do not accept a caller-supplied school ID.
- Worker operations use a per-request lease token. Claim, renewal, identity binding, profile writes, and worker state changes check the request token and lease expiry. External identity compensation is limited to the Auth identity returned by the private request-to-Auth mapping and verified against the requested email.
- Usernames are resolved server-side, matched case-insensitively, and login failures are generic. The Edge Function applies identifier/IP throttling through a service-only RPC.
- Browser source uses the publishable key only. `SUPABASE_SERVICE_ROLE_KEY` appears only in the server-side shared Edge helper.
- Laravel compatibility changes retain existing non-null password hashes and exclude null-hash profiles from Laravel password authentication/reset lookup. This does not synchronize Laravel and Supabase passwords.

These are source-level findings, not proof of PostgreSQL or hosted behavior.

## Confirmed local corrections made

1. `supabase/functions/_shared/http.ts`
   - Removed localhost origins from the production default allowlist.
   - Changed explicit `NEXASMS_ALLOWED_ORIGINS` values to replace the default, rather than append to it. Production can now explicitly omit development origins.
   - Added `x-client-info` to allowed CORS request headers for Supabase JS browser calls.
2. `supabase/config.toml`
   - Explicitly set `verify_jwt = true` for `provision-school` and `provision-school-user`; the three functions that perform their own authentication retain `false`.
3. `docs/deployment/online-deployment-readiness.md`
   - Updated the CORS/JWT findings and recorded the legacy API-key transition window.

No SQL, Laravel, or Vue application files were changed during this review.

## Remaining security and compatibility risks

### Critical deployment blocker: SQL has not been runtime-validated

`supabase/migrations/20261105161648_authentication_foundation.sql` is a 966-line rejected proposal. Its behavior depends on PostgreSQL privileges, `FORCE ROW LEVEL SECURITY`, SECURITY DEFINER behavior, transaction-local JWT subject settings, the existing audit triggers, Auth API responses, and lock/lease races. Previous documentation confirms those database integration, Auth, and concurrency tests were not run. Do not apply it or deploy dependent functions until that validation and independent re-review pass.

The migration drops `NOT NULL` from `public.users.password`. Laravel changes are coordinated locally, but need validation against a disposable PostgreSQL database and the actual Laravel account/password paths before production use. Existing Laravel hashes remain usable; no password synchronization is implemented.

### Hosted Auth signup setting is unknown

Before enabling account provisioning, verify in the existing Nexa SMS Dashboard that **Allow new users to sign up** and anonymous sign-in are disabled. Set the production Site URL and exact recovery redirect allowlist and verify SMTP delivery. None of those hosted Auth settings were changed or verified.

### Operator token is a privileged bearer credential

The bootstrap and pre-owner recovery endpoints are intentionally callable without gateway JWT verification. Their authorization depends on high-entropy server-only tokens, correct secret provisioning, strict rotation/removal, and the SQL RPC grant boundary. The recovery token is not an individually attributable operator identity. Keep both tokens out of browser and Vercel variables.

### Legacy Edge API key variables have a near-term transition

The shared helper reads `SUPABASE_ANON_KEY` and `SUPABASE_SERVICE_ROLE_KEY`. Supabase's current documentation says the legacy keys are supported through the end of 2026. A reviewed migration to the publishable/secret key environment or server SDK is required before the legacy window ends. Do not replace the service key with a new secret key in this code without adapting client authorization headers; those key formats have different gateway behavior.

### Static Vercel hosting is not the complete application

The Vercel SPA configuration is structurally present, but the GitHub branch does not contain the dirty local worktree, and the linked Vercel project settings/production build were not verified in this review. Even with the static frontend deployed, the absent hosted auth RPCs and Edge Functions prevent users from completing the implemented sign-in/context flow. Feature APIs for the full school-management system remain future work.

## Deployment dependency order after approval

1. Complete isolated PostgreSQL and Auth-compatible migration tests, then obtain a fresh independent review of the corrected SQL and functions.
2. Verify hosted signup/anonymous-auth settings, Site URL, recovery redirects, and SMTP in the existing Nexa SMS project.
3. Receive explicit approval to apply the reviewed auth migration. Confirm the pending migration list contains only the expected reviewed version before applying.
4. Set only the required server-side bootstrap/operator secrets securely; confirm Supabase-provided runtime values are available. Never place the service key or operator tokens in Vercel.
5. Deploy `login-username`, `provision-school`, `provision-school-user`, and `provisioning-recovery`; deploy `bootstrap-owner` only with its token installed and controlled bootstrap procedure ready. Use the checked-in per-function JWT settings.
6. Provision the initial owner once; verify invite delivery, Auth-to-profile linkage, permission context, and secret removal/rotation.
7. Configure the existing Vercel project with `frontend/` as root and only `VITE_SUPABASE_URL` and `VITE_SUPABASE_PUBLISHABLE_KEY`; verify production SPA paths and authentication.

No command in this sequence was run.

## Checks performed

- Read-only hosted project status, migration history, and deployed-function inventory.
- Static source inspection of the auth migration, shared Edge helpers, all five Edge entrypoints, config, frontend session flow, Laravel password provider/config/controller, and prior Stage 1B reports.
- Git status inspection before and after changes to preserve the pre-existing dirty worktree.
- No local tests, builds, migrations, account operations, hosted Auth changes, Edge deployments, commits, or pushes.

## Remediation update (2026-11-06)

This report predates the current local remediation. The affected controls now have a proposal described in [Stage 1 security remediation](stage-1-security-remediation.md): database-locked one-time bootstrap, private Auth identity mapping, streamed request limits, and expiry-aware operator tokens. No migration was applied and no functions were deployed. No local tests were run; the updated GitHub Actions workflow has not run. The original findings remain pending independent review and isolated CI evidence.
