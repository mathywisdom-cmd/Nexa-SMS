# Nexa SMS Stage 1B — Isolated Runtime Validation

**Date:** 2026-11-05
**Status:** BLOCKED — runtime validation has not been completed.
**Migration:** `supabase/migrations/20261105161648_authentication_foundation.sql` remains rejected and unapplied to the hosted project.

## Safety and worktree

- Repository: `D:\Projects\lav_sms-master\lav_sms-master`; branch `deployment/vercel-supabase-poc`.
- The worktree already contains modifications, deletions, and untracked files. All were preserved. No reset, clean, stash, checkout, commit, or push was performed.
- No hosted Supabase project, Velocity project, Auth setting, account, school, or database was accessed or changed.
- During the initial Stage 1B validation attempt, only this report was created. Stage 1B1 subsequently changed only the still-unapplied authentication proposal, provisioning Edge source, test sources, and the Stage 1B review/report. No Laravel or frontend design files were changed.

## Runtime availability

PowerShell checked `deno`, `psql`, `postgres`, `pg_ctl`, `initdb`, `docker`, `supabase`, `podman`, `node`, `npm`, and `npx` with `Get-Command`, then ran `node --version` and `npm --version`.

| Tool | Result |
|---|---|
| Deno | Not installed/on PATH |
| `psql`, `postgres`, `pg_ctl`, `initdb` | Not installed/on PATH |
| Docker, Podman | Not installed/on PATH |
| Supabase CLI | Not installed/on PATH |
| Node.js | v24.21.0 |
| npm | 11.19.0 |
| npx | Available |

There is no available database engine or compatible container runtime. PostgreSQL cannot parse/apply the migration; SQL catalog checks, Auth/JWT behavior, RLS, trigger execution, transactions, and cross-session concurrency cannot be validated here. Deno tests cannot execute without Deno.

Supabase's Windows local-development guide requires a Docker-API-compatible runtime (Docker Desktop is recommended on Windows); its experimental native runtime supports Linux and macOS Apple silicon, not Windows. The CLI can be installed outside the repository with Scoop, and Deno with Winget. See the [Supabase local CLI guide](https://supabase.com/docs/guides/local-development/cli/getting-started), [Supabase CLI v3 notes](https://supabase.com/docs/guides/local-development/cli/migrating-to-v3), and [Deno Windows installation guide](https://docs.deno.com/runtime/getting_started/installation/).

## Checks actually executed

| Check | Command | Result |
|---|---|---|
| Static SQL/function checks | `node supabase/tests/authentication-foundation-static.mjs` | PASS — “Static auth foundation checks passed (16 SQL functions, 48 signature checks).” Static inspection only, not PostgreSQL validation. |
| TypeScript syntax transpilation | One-off Node script using installed TypeScript `transpileModule` on `supabase/functions/**/*.ts` and `supabase/tests/**/*.ts` | PASS — 9 files, 0 syntax diagnostics. Not a Deno type-check or test run. |
| Whitespace check | `git diff --check` | PASS; Git printed line-ending conversion warnings for pre-existing modified files. |
| Git state | `git status --short --branch` | Confirmed branch and existing changes; preserved. |

An initial TypeScript enumeration attempt used Node `child_process.execSync('rg ...')` and failed with Windows `spawnSync ... EPERM`. That was a harness process-spawn restriction, not a source diagnostic; the syntax pass was rerun using Node filesystem traversal and passed.

## Existing test assets inspected

| Asset | Scope | Status |
|---|---|---|
| `supabase/migrations/20261105161648_authentication_foundation.sql` | Profile linking, owner/school/user provisioning, private ledger, idempotency, leases, cancellation/recovery, grants and audit checks | Not applied or parsed by PostgreSQL |
| `supabase/tests/authentication_foundation.sql` | One `DO` block checks schema/catalog state, password nullability, grants, function privileges, ledger FORCE RLS, and owner index | Not executed |
| `supabase/tests/authentication-foundation-static.mjs` | Static checks for function declaration/signature/ownership/privilege references and selected invariants | Executed; passed |
| `supabase/tests/provisioning-recovery.test.ts` | 11 Deno fake-service tests for invitation errors/timeouts, profile failure/retry, lost response, idempotency conflict, concurrent duplicate requests, competing owner requests, identity conflict, compensation/retry, and orphan marker | Not executed; it is a mock suite, not SQL/Auth integration |
| `docs/deployment/stage-1b-provisioning-recovery.md` | Design, trust boundary, state machine, recovery behavior, risks and earlier coverage matrix | Reviewed; remains proposal-only |

## Requested runtime matrix

**None of the following database-backed scenarios ran.** “Fake test exists” means only that a mock test is present in source.

| Requested validation | Existing coverage | Result here |
|---|---|---|
| Parse/apply complete migration | Migration file present | NOT RUN — PostgreSQL/Supabase CLI unavailable |
| SQL ownership and privilege assertions | SQL catalog test and static signature test | Static check passed; SQL test NOT RUN |
| Concurrent first-owner bootstrap | One Deno fake concurrency test; advisory lock/unique index only inspected statically | Fake NOT RUN; real database concurrency NOT RUN |
| Concurrent school provisioning | Deno fake same-request concurrency test | Fake NOT RUN; real database concurrency NOT RUN |
| Concurrent school-user provisioning | No dedicated SQL/Auth concurrency test found | NOT RUN; integration test needed |
| Lease acquisition and competing workers | SQL claim RPC and fake claim behavior exist | NOT RUN against PostgreSQL |
| Lease expiry/reclaim | SQL has a 10-minute expiry; fake suite has no controllable clock/expiry test | NOT RUN; deterministic test needed |
| Lease renewal | No renewal RPC/function or worker call exists | NOT TESTABLE — implementation gap |
| Cancellation in `pending` / `identity_created` / `profile_created` | SQL rejects cancellation in active states until reconciliation; no dedicated rejection test for each state found | NOT RUN; test rejection then reconcile-and-cancel |
| Cancellation in recoverable/terminal/`cancelling` states | Partial Deno fake cancellation coverage | NOT RUN; per-state SQL and Deno tests needed |
| Invitation timeout, profile failure, response loss, failed compensation | Deno fake tests exist | NOT RUN; no real Auth/Postgres fault injection |
| Transaction-local JWT subject and audit actor/school | Migration/static source only | NOT RUN with real local JWT/database trigger |
| Duplicate key/conflicting payload | Deno fake and SQL reservation logic | NOT RUN against actual locks/constraints |
| Orphan identity recovery | Deno fake exact-marker test | NOT RUN against local Auth |
| Unauthorized recovery | Source and SQL privilege assertions | NOT RUN with actual JWT roles/RPC grants |

## Concrete gaps and proposed correction

1. **Lease renewal is absent.** The migration defines `claim_provisioning_request(uuid, uuid)` with a 10-minute expiry, but no renewal RPC exists and the Edge worker does not renew. A long Auth invitation or paused worker could outlive its lease and overlap a reclaimed worker. Proposed correction: add a service-role-only `renew_provisioning_claim` RPC that locks the request and extends expiry only for a matching, still-live lease token and eligible state; have the worker renew before a potentially long operation and stop writes if renewal fails. Add tests for successful renewal, competing workers, stale token, expired lease, and ineligible states. This needs a reviewed migration/code change; none was made without a runnable database.
2. **Mock concurrency does not prove PostgreSQL concurrency.** The fake cannot validate advisory locks, row locks, unique indexes, transaction boundaries, or lease expiration. Use independent database sessions and local Supabase Auth in an isolated stack.
3. **The SQL test is catalog-only.** It does not provision Auth users, execute provisioning RPCs, open concurrent sessions, or exercise the audit trigger with real JWT subject claims.
4. **Cancellation is reconciliation-gated.** Active `pending`, `identity_created`, and `profile_created` requests are not directly cancellable. Verify each rejection and test cancellation after reconciliation to a recoverable state, including a retry after response loss in `cancelling`.
5. **The migration remains rejected.** Static checks do not clear previous security-review findings or resolve operational issues described in `stage-1b-provisioning-recovery.md`.

## Safe local-only setup and validation sequence

`supabase/config.toml` contains the hosted Nexa project reference in `project_id`. Local `start`/`reset` commands target the local stack, but run the work in a disposable copy/checkout with separate Supabase state. Do not use `supabase link`, `--remote`, `--linked`, hosted configuration commands, or deployment commands.

Install prerequisites in Windows (these OS-level installations were not performed):

```powershell
# Install/start Docker Desktop using its official Windows installer; verify `docker info`.
scoop install supabase
winget install DenoLand.Deno

# Open a new PowerShell session and verify:
docker info
supabase --version
deno --version
```

In a disposable project copy, after confirming local ports are free and the CLI is not linked:

```powershell
supabase start
supabase status
supabase db reset --local --no-seed
supabase db query --local --file supabase/tests/authentication_foundation.sql
supabase db lint --local --fail-on error
deno test --allow-net supabase/tests/provisioning-recovery.test.ts
```

`db reset --local --no-seed` intentionally recreates the disposable local database and applies every local migration, including the currently rejected authentication proposal. Never use `--remote` or `--linked`. Verify the local service URLs in `supabase status` before queries. The `npm:` import in the Deno test may need network access to fetch its dependency.

Then add/run local integration tests for:

- Two independent owner-bootstrap requests synchronized at the database critical section; assert one owner and a safe loser outcome.
- Concurrent school and school-user provisioning; same-key/same-payload retries; same-key conflicting payload; and distinct request IDs.
- Lease claim contention, expiry/reclaim, renewal, stale-token rejection, and cancellation blocked by an active lease.
- Each cancellation state and crash point; invitation/deletion timeout; profile failure; lost response after committed transaction; failed compensation followed by recovery.
- Real Auth JWT subject mapping, transaction-local subject cleanup/rollback, audit actor mismatch, school mismatch, and legitimate platform-owner/null-school audit behavior.
- Anonymous/authenticated/service-role/database-owner privileges, including direct ledger and provisioning RPC denial for browser roles.
- Unauthorized recovery with missing/invalid operator token, school-admin JWT, revoked owner permission, and allowed owner/operator cases.

Use only ephemeral local synthetic identities (reserved `.test` addresses) in the disposable stack. No real people or production records are needed.

## Remaining risks and verdict

- Migration parsing/application, actual grants/ownership, FORCE RLS, SECURITY DEFINER behavior, and Postgres function execution remain unverified.
- SQL locking, concurrency, lease/cancellation races, JWT context and audit enforcement remain unverified.
- Lease renewal is a code gap and potential long-running-worker race.
- The 11-test Deno source suite was not executed.
- Hosted Auth settings and all hosted databases remain untouched.

**Verdict: Stage 1B remains incomplete and is not ready for runtime or production approval.** Install the isolated Windows prerequisites, implement lease renewal with tests, then run the complete migration, SQL catalog/behavioral tests, concurrent workers and Deno suite before independent review.

---

## Stage 1B1 — Provisioning lease renewal implementation update

**Status:** Implemented as local proposal/code only; PostgreSQL and Deno execution remain blocked. The authentication migration remains rejected and has not been applied.

### Changes made

- Added `public.renew_provisioning_lease(uuid, uuid)` to the existing unapplied authentication migration. It is `SECURITY DEFINER`, owned by `postgres`, uses an empty fixed `search_path` and `row_security = off`, and is executable only by `service_role`.
- Renewal locks the request row and requires the exact per-invocation UUID token, a nonterminal worker state, and an expiry later than `clock_timestamp()`. It extends that request's lease by ten minutes. Expired, replaced, cancelling, terminal, unknown-request and wrong-token renewals raise an error.
- The worker token now gates identity binding, each of the three profile-provisioning RPCs, and worker state transitions. Those writes lock the same row and verify the token and live expiry. Profile creation keeps the lease through `profile_created`; the worker's token-checked `completed` transition releases it.
- The Edge runner renews every two minutes while external Auth work may be running. A renewal failure is sticky for that invocation: the runner stops before further database writes, attempts a token-checked recoverable transition if still owner, and otherwise leaves state for expiry/reconciliation. Outer Edge handlers no longer attempt tokenless state changes.
- Updated SQL privilege assertions and static migration checks. Added six fake-service tests for renewal extension, expiry, competing workers, wrong worker, cancellation/terminal rejection, and failure followed by safe retry.

### Current checks

| Check | Result |
|---|---|
| `node supabase/tests/authentication-foundation-static.mjs` | PASS — 17 declared SQL functions and 51 ownership/privilege signature references. Static only. |
| TypeScript syntax transpilation with installed TypeScript `transpileModule` over Supabase function/test `.ts` files | PASS — 9 files, 0 syntax diagnostics. Not a Deno type check or test run. |
| `git diff --check` | PASS — no whitespace errors. Git emitted only line-ending conversion warnings for existing modified files. |
| `deno test supabase/tests/provisioning-recovery.test.ts` | NOT RUN — Deno unavailable. The suite now contains 17 tests, including the new fake-service lease cases, but they have not executed. |
| Migration parse/application and `authentication_foundation.sql` | NOT RUN — PostgreSQL, Docker-compatible runtime and Supabase CLI unavailable. |
| SQL concurrency/renewal/cancellation/audit tests | NOT RUN — no isolated database. |
| Hosted project / Velocity | NOT accessed. |

The required CLI migration-generation command was attempted as `npx --yes supabase@v2.stable migration new provisioning_lease_renewal`; npm returned `ENOTCACHED` because the CLI package was not present in the offline npm cache. No package files were changed. Since the authentication proposal is still unapplied and rejected, the renewal function and signature changes were added to that existing proposal instead of creating a separate migration-history entry.

### Review notes and remaining limits

- The lease token is the invocation-specific worker identity/capability; it is never returned to browsers. It is bound to a single request and compared on every lease-sensitive mutation.
- Renewal is an RPC over the existing service-role server boundary. No browser grant or general table access was added.
- SQL row locking and token checks are designed to serialize renewal, cancellation, claim, and profile writes, but this has not been verified with concurrent PostgreSQL sessions.
- The six Deno tests are source coverage only until run. No actual renewal, claim contention, lease expiry, edge timer, Deno runtime, RLS, or Auth behavior is proven by the checks above.
- A one-off `transpileModule` pass is syntax-only; execute the Deno test suite and Supabase local migration/integration tests after installing the runtime prerequisites documented earlier in this report.

**Stage 1B1 verdict:** implementation is present, but Stage 1B remains incomplete. Static checks passed; Deno tests and PostgreSQL/runtime behavior remain unverified. Do not apply this rejected migration or deploy Edge Functions until isolated runtime tests and another independent security review pass.

---

## GitHub Actions validation environment prepared

**Status:** Workflow and runtime test source added locally; **not executed**. The authentication migration remains rejected and unapplied to the hosted project.

### Files added or updated for isolated CI

- `.github/workflows/isolated-auth-validation.yml` starts a fresh local Supabase stack in a GitHub-hosted Ubuntu runner, applies all migrations only to its disposable PostgreSQL database, runs the migration static/catalog checks, existing Deno recovery mocks, real local Auth/Postgres integration source, and the Laravel nullable-password compatibility PHPUnit test. It shuts down the stack even on failure.
- `supabase/tests/authentication-provisioning.integration.ts` exercises temporary Auth identities and records exclusively in that disposable CI stack: competing owner bootstrap, duplicate reservation, two school tenants, actual JWT/profile lookup, staff RLS, role and actor restrictions, lease renewal/competing claim, cancellation and compensation confirmation, and audit actor binding.
- The same CI job serves all local Edge Functions and invokes the login, bootstrap, protected-school, and recovery handlers over HTTP for invalid-credential, bootstrap-token, JWT-required, and unauthorized-recovery cases.
- The real integration suite uses the runner's `psql` client to set transaction-local service-role JWT claims, confirming audit actor matching, cross-school actor rejection, and platform-owner audit behavior. Actual Auth JWT/RLS behavior is exercised separately through the local Auth/PostgREST API.
- `supabase/tests/authentication_foundation.sql` now checks PostgreSQL function ownership/fixed search paths, audit trigger timing/binding/enabled state, and the validated owner constraint in addition to existing grants/RLS checks.
- `supabase/migrations/20261105161648_authentication_foundation.sql` corrects completed/terminal idempotency retries: after the request payload is intentionally cleared, equality is checked using the retained canonical payload hash; active requests still compare both hash and JSON payload. This was a confirmed code-path defect found by reviewing the runtime retry scenario; the migration remains unapplied.
- `supabase/tests/authentication-foundation-static.mjs` checks the idempotency correction.
- `docs/deployment/github-actions-auth-validation.md` documents scope, evidence boundaries, coverage limits, and execution/approval boundary.

### Results

No tests were run in this turn, locally or remotely, in accordance with the instruction not to run tests on the computer and not to touch hosted Supabase. The GitHub Actions workflow cannot run until its file and referenced tests are published to GitHub; commit/push were expressly not authorized. Consequently there are **no new passing results** to report. The workflow is designed to fail closed on migration/test errors, but its syntax, action availability, migration result, SQL assertions, Deno type check/runtime, and PHPUnit result still need an actual GitHub Actions run.

### Remaining test gaps

- The fake-service Deno suite models Auth invite/network timeout, lost HTTP responses, failed compensation, retry, duplicate requests, and cancellation failure; those fault injections are not reproduced against the local Auth service.
- The real integration suite checks concurrent owner bootstrap and live lease competition/renewal. It does not wait ten minutes for an actual database lease expiry/reclaim race, nor force PostgreSQL/Auth network failure during a transaction.
- No database migration test is included for Laravel's full application schema; the Laravel PHPUnit test covers only the custom legacy password provider's handling of non-null versus NULL hashes.

**Current verdict:** isolated validation environment prepared in the local worktree, but Stage 1B remains incomplete and the migration is not approved for the existing hosted Supabase project. Next, publish the reviewed workflow in a branch/PR and inspect the actual CI run; then resolve any failures and request independent security review before production deployment approval.
