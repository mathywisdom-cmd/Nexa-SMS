# Nexa SMS online deployment readiness

**Audit date:** 2026-11-05

**Repository:** `D:\Projects\lav_sms-master\lav_sms-master`

**Branch:** `deployment/vercel-supabase-poc`

**Supabase project:** `vyiyveqnsjyxrekbwdeg` (Nexa SMS, Postgres 17.11, `ACTIVE_HEALTHY`)

**Frontend URL supplied:** `https://nexa-smsv.vercel.app`

## Decision

**Do not deploy the current authentication/provisioning implementation yet.** The hosted project is the intended Nexa SMS project, but it has only the nine schema/security migrations, no Auth identities or application profiles, no school records, and no Edge Functions. The local tenth migration is the still-rejected authentication/provisioning proposal. The functions call RPCs and depend on a private ledger created by that proposal; those objects do not exist remotely. In particular, the frontend session loader expects `get_my_auth_context()`, and the username-login function expects `consume_username_login_attempt()`; neither RPC exists in the hosted database.

A Vercel-only static deployment is technically separable, but it would not make the school management application operational. The current local frontend work is also uncommitted and unpushed, so a Git-connected Vercel build cannot receive it from GitHub yet. Do not treat the existing Vercel URL as a verified deployment: this audit could not load it with the available web tool, and the local Vercel CLI was not authenticated/usable to inspect its project settings.

## 1. Repository and worktree

- Current branch: `deployment/vercel-supabase-poc`.
- HEAD: `0fe8e9a` (`Fix cross-platform Vercel build dependencies`); `origin/deployment/vercel-supabase-poc` is the tracking branch.
- Worktree is **not clean**. Existing modifications include Laravel auth compatibility files, Vue app/auth files, frontend package files, Supabase functions/migrations/tests, and deployment documentation. Two earlier proposal migration paths are deleted in the working tree; their proposal copies exist under `supabase/proposals/`. These changes were preserved.
- No commit, push, deployment, hosted migration, Auth configuration change, or account creation was performed for this audit.
- The repository contains `frontend/.env.example`, not a production environment file. `.gitignore` excludes environment files, `node_modules`, and `frontend/dist`.
- Vercel CLI 62.2.0 is installed, but `vercel whoami` did not complete in this environment. There is no `.vercel` project-link directory in the repository. Account, project linkage, and Vercel environment-variable configuration therefore remain unverified.

## 2. Migration reconciliation and live schema

The remote migration ledger contains these nine records, matching the local canonical migration versions and names:

| Version | Name |
|---|---|
| `20261104164653` | `nexasms_initial_schema_20261104` |
| `20261104211902` | `stage1_authorization_foundation_20261104` |
| `20261104220804` | `stage2a_reference_catalog_read_20261105` |
| `20261104223516` | `stage2b_school_academic_read_20261105` |
| `20261104224142` | `stage2c_academic_configuration_read_20261105` |
| `20261104225454` | `stage2d_settings_timetable_read_20261105` |
| `20261104225531` | `stage2e_library_dorm_catalog_read_20261105` |
| `20261104230835` | `stage2f_attendance_read_20261105` |
| `20261104231529` | `stage2g_student_staff_records_read_20261105` |

The reconciliation report records a byte-for-byte/MD5 comparison of the nine local SQL files to their stored migration statements: [migration-history-reconciliation.md](migration-history-reconciliation.md). The local extra file `supabase/migrations/20261105161648_authentication_foundation.sql` is not in the remote ledger and is not applied. It must remain blocked pending isolated Postgres validation and independent security review.

Read-only remote catalog checks found:

- 40 public tables; RLS is enabled and forced on all 40.
- 26 public policies, all `SELECT` policies; no authenticated table `INSERT`, `UPDATE`, or `DELETE` grants were present. `anon` has no public-table `SELECT` grants.
- 14 public tables have no policy (also reported as informational Security Advisor findings). The inspected grants do not expose those tables to `anon` or `authenticated`.
- Existing tenant-data read policies use `current_school_id()` plus a permission check. Reference/authorization catalog read policies allow any non-null Auth UID to read their catalog rows; they do not provide write access.
- Public catalog counts include 11 user types, 11 roles, 32 permissions, and 81 role-permission links. These are authorization definitions, not user accounts.
- `auth.users`, `public.users`, and `public.schools` each contain zero rows. The `users.password` column is currently `NOT NULL`; `auth_user_id` exists and is nullable.
- Existing auth helper functions (`current_profile_id`, `current_school_id`, `has_permission`) and the audit/profile/school guard triggers exist. `get_my_auth_context` and all provisioning/login-rate-limit RPCs expected by the local auth/provisioning code are absent. The private provisioning ledger does not exist.
- No Edge Functions are deployed.

This live observation supersedes older local notes that described zero remote policies. No user records or sensitive columns were read.

## 3. Auth migration and Edge Function compatibility

The unapplied authentication migration expects existing `public.users`, `public.schools`, roles/permissions and guard functions, which are present. It also proposes to drop `NOT NULL` from `public.users.password`, create the private provisioning ledger and add Auth-context, login rate-limit, reservation, claim, renewal, identity-binding, provisioning, recovery and cancellation RPCs. Those dependencies are absent remotely. Applying only the Edge Functions, without the reviewed migration, would fail at their first relevant RPC calls.

The migration's password constraint change is a coordinated compatibility change, not a harmless deployment toggle: the hosted column is currently `NOT NULL`. The proposal and Laravel password-provider changes require the prior password-transition/security review and isolated Postgres tests. Do not apply it based on this readiness audit.

Lease use in the local worker code is consistent at the database mutation boundaries:

- `claim_provisioning_request` creates a request-scoped per-invocation token.
- `record_provisioning_identity`, the three profile provisioning RPCs, and worker state transitions receive and validate the token.
- The worker renews via `renew_provisioning_lease` every two minutes during external Auth operations. Short profile writes instead validate the still-live lease in their own transaction; `profile_created` retains the lease until the token-checked completion transition.
- Recovery/cancellation paths are operator operations, not worker-owned mutations.

This is source inspection only. PostgreSQL row-lock/race behavior, Deno execution, lease expiry, Supabase Auth timeouts, and recovery have not passed runtime tests. The Stage 1B migration remains rejected.

### Functions and files intended for a later deployment

Deploy only after the migration is independently approved, applied through the approved process, and isolated integration tests pass:

| Function | Entry point | JWT verification in current config | Notes |
|---|---|---|---|
| `login-username` | `supabase/functions/login-username/index.ts` | `false` | Public login endpoint; performs internal input checks/rate-limit RPC and generic login errors. Its required rate-limit RPC is currently absent remotely. |
| `bootstrap-owner` | `supabase/functions/bootstrap-owner/index.ts` | `false` | Requires a high-entropy bootstrap header secret; deployment must not proceed until that secret is provisioned securely. |
| `provision-school` | `supabase/functions/provision-school/index.ts` | `true` (explicit) | Also validates caller JWT/profile/permission in function code. |
| `provision-school-user` | `supabase/functions/provision-school-user/index.ts` | `true` (explicit) | Also validates caller JWT/profile/permission in function code. |
| `provisioning-recovery` | `supabase/functions/provisioning-recovery/index.ts` | `false` | Verifies owner JWT internally or requires a separate operator token for owner-bootstrap recovery. Protect that token and restrict CORS. |

Each deployment must include its entry point and imported shared files: `supabase/functions/_shared/http.ts`, `_shared/provisioning.ts`, and `_shared/recovery.ts` as required by each entry point. The underscore-prefixed shared directory follows the Supabase shared-function layout. Dependencies are imported with the pinned `npm:@supabase/supabase-js@2.119.0`; there is no function `deno.json` or import map.

The public login and bootstrap endpoints deliberately use application-level secret/auth checks because gateway JWT verification is disabled. Keep that setting only while those checks are retained and reviewed. The authenticated provisioning endpoints explicitly set `verify_jwt = true`; do not deploy them with `--no-verify-jwt`.

The latest local review also narrowed default CORS to the production Vercel origin, made `NEXASMS_ALLOWED_ORIGINS` an explicit replacement allowlist (so production can omit localhost), and added `x-client-info` for Supabase JS browser preflights. Local development origins must be explicitly included in a local-only `NEXASMS_ALLOWED_ORIGINS` setting.

## 4. Signup and Auth configuration

`supabase/config.toml` contains `[auth] enable_signup = false`, which is local CLI project configuration; it is not proof of the hosted Auth setting. The remote project metadata available in this audit does not expose hosted signup configuration. Disable and verify it in the existing project's Dashboard under **Authentication → General Configuration** using **Allow new users to sign up**. Also verify **Allow anonymous sign-ins** is off. Supabase documents that disabling “Allow new users to sign up” prevents new users from signing up while existing users can still sign in ([Auth general configuration](https://supabase.com/docs/guides/auth/general-configuration)). Do not use a public registration route as a substitute.

Before invitations/recovery are relied upon, configure the hosted Auth Site URL and redirect allowlist to include `https://nexa-smsv.vercel.app` and the exact recovery/reset callback paths used by the Vue app. Configure a production SMTP provider and verify an invitation and password-recovery email end-to-end. Those settings were not changed or verified here.

## 5. Frontend and Vercel

`frontend/` is an independent Vue 3 + Vite app. Its client reads only:

- `VITE_SUPABASE_URL`
- `VITE_SUPABASE_PUBLISHABLE_KEY`

The example URL points at the intended Nexa SMS project. Vercel must receive the publishable key only; never set a service-role/secret key under a `VITE_` name or in browser code. Edge Function source reads `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_SERVICE_ROLE_KEY`, plus custom server-only secrets below.

`frontend/vercel.json` rewrites `/(.*)` to `/index.html`, so SPA deep links such as `/login` and `/dashboard` have the required fallback. `frontend/vite.config.ts` builds to `dist`.

Required Vercel project settings:

| Setting | Value |
|---|---|
| Root Directory | `frontend` |
| Framework Preset | Vite |
| Install Command | `npm ci` |
| Build Command | `npm run build` |
| Output Directory | `dist` |
| Environment variables | `VITE_SUPABASE_URL`, `VITE_SUPABASE_PUBLISHABLE_KEY` (Production and Preview only if preview points to a safe backend) |

These match Vercel's Vite and SPA guidance ([Vite on Vercel](https://vercel.com/docs/frameworks/frontend/vite), [Build settings](https://vercel.com/docs/builds/configure-a-build)). The actual linked Vercel project settings and environment values were not accessible in this audit.

Checks run from `frontend/`:

- `npm exec vue-tsc -- --noEmit`: passed.
- `npm run test:auth`: passed, 11 authorization-routing checks.
- `npm run build -- --outDir <temporary-directory>`: failed in this restricted Windows environment with `spawn EPERM` from esbuild while Vite bundled `vite.config.ts`. The output target was outside the project, so existing `frontend/dist` was not overwritten. This does not establish whether Vercel's Linux build will fail; the production build must be verified in Vercel CI or an unrestricted compatible runner.

## 6. Required environment and secret names

### Vercel browser build

- `VITE_SUPABASE_URL`
- `VITE_SUPABASE_PUBLISHABLE_KEY`

### Supabase Edge Functions

Supabase supplies its standard function environment (`SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_SERVICE_ROLE_KEY`) to the hosted runtime; the last one must remain server-only and must never be copied to Vercel browser variables.

Custom names read by this source:

- `NEXASMS_OWNER_BOOTSTRAP_TOKEN` — required for first-owner bootstrap; use a randomly generated high-entropy value and remove/rotate after bootstrap.
- `NEXASMS_PROVISIONING_OPERATOR_TOKEN` — required for owner-bootstrap recovery before an owner can authenticate; keep server-side and disable/rotate once no longer needed.
- `NEXASMS_ALLOWED_ORIGINS` — optional comma-separated exact origins; when set, it replaces the production default. Do not use `*`; list only approved origins.

The source currently uses the Supabase legacy Edge runtime key variables `SUPABASE_ANON_KEY` and `SUPABASE_SERVICE_ROLE_KEY`. Supabase documents these legacy keys as supported through the end of 2026. Plan a reviewed server-key migration before then; never substitute a secret key into browser code or a `VITE_` variable.

No secret values are included in this report.

## 7. Deployment commands (future, gated)

These commands were **not run**. Do not run the database command until the authentication migration is approved after independent security review and isolated runtime validation.

### Frontend

From the existing linked Vercel project, set Root Directory to `frontend`, then build/deploy the reviewed commit through the Vercel Git integration. CLI fallback after verifying the account and linking the existing `nexa-smsv` project:

```powershell
Set-Location D:\Projects\lav_sms-master\lav_sms-master\frontend
vercel link
vercel --prod
```

The GitHub branch currently does not contain the local uncommitted auth/provisioning changes, so a Git-triggered production deployment cannot include this working tree until the project owner reviews and explicitly authorizes publication.

### Supabase (only after all blockers are cleared)

Install/authenticate the CLI, verify the selected project ref, and link the exact existing project:

```powershell
Set-Location D:\Projects\lav_sms-master\lav_sms-master
supabase login
supabase link --project-ref vyiyveqnsjyxrekbwdeg
supabase migration list --remote
supabase db push --dry-run --remote
```

After the migration has been independently approved and isolated tests pass, inspect the dry-run output. The expected pending migration is a production schema/security change. Applying it requires separate explicit authorization:

```powershell
supabase db push --remote
```

Do not run that apply command as part of a routine function deploy. The commands use explicit `--remote` because current Supabase CLI v3 targets local services by default unless a remote target is explicitly requested.

Set only the custom Edge secrets from a secure, ignored file outside the repository, then deploy the five function names individually so each uses the checked-in `config.toml` JWT setting:

```powershell
supabase secrets set --env-file D:\secure\nexasms-edge.env --project-ref vyiyveqnsjyxrekbwdeg
supabase functions deploy login-username --project-ref vyiyveqnsjyxrekbwdeg
supabase functions deploy bootstrap-owner --project-ref vyiyveqnsjyxrekbwdeg
supabase functions deploy provision-school --project-ref vyiyveqnsjyxrekbwdeg
supabase functions deploy provision-school-user --project-ref vyiyveqnsjyxrekbwdeg
supabase functions deploy provisioning-recovery --project-ref vyiyveqnsjyxrekbwdeg
```

`D:\secure\nexasms-edge.env` should contain only the custom names above and must not be committed. Do not pass service-role credentials as `VITE_` values. Current CLI command forms are documented in Supabase's [Edge Function deployment](https://supabase.com/docs/guides/functions/deploy) and [secrets](https://supabase.com/docs/guides/functions/secrets) documentation.

## 8. Blockers and recommended sequence

1. Preserve and review the dirty worktree. Decide which local auth/frontend files are ready to publish; do not deploy unreviewed changes from a stale GitHub commit.
2. Keep the authentication migration unapplied. Run the required isolated PostgreSQL/Deno validation and obtain a fresh independent security review. Reconcile any reviewer corrections into a new reviewed migration; do not overwrite the rejected proposal casually.
3. Confirm remote Auth signup and anonymous sign-in are disabled; set production Site URL/redirect allowlist, email confirmation, and SMTP.
4. Configure Vercel's existing project root/build/output and only the two frontend public variables. Confirm project linkage/account and run the production build in Vercel CI. Resolve the current local `spawn EPERM` environment failure or record CI as the trusted build evidence.
5. After schema approval/application, verify the auth-context and every provisioning/rate-limit RPC exists with expected signatures and grants; then set Edge secrets and deploy only the five named functions with the reviewed JWT configuration.
6. Provision the platform owner once through the protected bootstrap flow; verify invitation, login, profile, permissions and recovery; rotate/remove bootstrap credentials. No account was created in this audit.
7. Implement and migrate the remaining school-management write/read API workflows. The current remote RLS grants are read-only, and the five local functions cover login/provisioning/recovery only. They are not the complete application API.

**Overall:** frontend static hosting configuration is structurally suitable, but neither production build evidence nor Vercel linkage/settings are verified. Supabase schema history is reconciled, but the required authentication/provisioning migration and RPCs are not live. No working online application deployment is ready today.

## Stage 1 security remediation status (2026-11-06)

The current local proposal adds atomic one-time owner-bootstrap consumption, a private Auth identity map, expiry-aware bootstrap/operator tokens, rate/audit records, and streamed request-body limits. CI coverage has been authored for bootstrap concurrency/replay/expiry/rotation, metadata mutation and missing correlation, token expiry/rotation, and malformed or oversized bodies. The workflow targets only a fresh local Supabase stack. It has not run after these edits, and nothing was applied to the hosted project.

Supabase currently documents wall-clock limits of 150 seconds on Free and 400 seconds on paid plans, plus a separate 150-second request idle timeout ([Edge Function limits](https://supabase.com/docs/guides/functions/limits)). The ten-minute lease enables recovery after a stalled worker; it does not extend an HTTP request. Hosted runtime, signup settings, SMTP, proxy IP-header behavior, and production migration compatibility remain unverified. See [Stage 1 security remediation](stage-1-security-remediation.md); this is not deployment approval.
