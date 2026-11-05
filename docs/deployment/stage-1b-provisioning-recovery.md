# Nexa SMS Stage 1B — Durable Provisioning and Recovery

**Status:** local proposal only. The authentication migration remains **REJECTED and unapplied**. No hosted project, Auth setting, account, or database was changed.

## Scope and trust boundary

The proposal extends `supabase/migrations/20261105161648_authentication_foundation.sql` and the existing Edge Functions. Browsers still cannot call provisioning/recovery SQL RPCs or access the private ledger. Normal school provisioning first verifies the caller's Supabase JWT with `auth.getUser`, loads the linked profile and effective permissions using that user's JWT, and then calls the service-only SQL RPCs. SQL independently resolves the supplied Auth UUID to the linked profile and recomputes the relevant permission. School ownership is derived from that profile.

The three profile/school creation routines remain `SECURITY INVOKER`; their PostgREST request executes as the trusted `service_role`, which is already the intended server-only writer for guarded public tables. The migration grants that role only `SELECT`, `INSERT`, and `UPDATE` on the private request ledger, never `DELETE`. The table remains RLS-enabled and FORCE RLS; `anon` and `authenticated` receive no ledger table privileges. Security relies on keeping the service-role key inside Edge Function secrets. These RPCs are privileged server operations and must never be exposed through a browser client.

School and school-user SQL routines set `request.jwt.claim.sub` transaction-locally to the actor Auth UUID only after checking the ledger binding, current profile, role, school and effective permission. This is required by the existing audit trigger: a service-role JWT may otherwise have no Auth subject, and the trigger intentionally rejects a non-null actor in that context. The existing trigger then resolves the linked profile again and enforces actor-school consistency. The setting lasts only for that database transaction and is never derived from browser-supplied school or role metadata.

Recovery accepts either:

- A verified platform-owner JWT linked to a `super_admin` profile with `platform.schools.manage`, for ordinary provisioning requests; or
- A separate server-side `NEXASMS_PROVISIONING_OPERATOR_TOKEN`, restricted in the Edge Function to `owner_bootstrap` requests so the first owner can be recovered before an owner account exists.

The recovery function disables gateway JWT verification only because it supports the operator-token branch; it still verifies a supplied owner JWT itself and rejects requests without either credential. The operator token represents a trusted operator principal but does not identify a natural person. A person-specific audit trail requires platform-owner JWT authentication or a future named operator identity mechanism. The token is never stored in the ledger or returned.

## Durable ledger

`private.provisioning_requests` records a UUID idempotency/request key, operation, verified actor Auth UUID where applicable, normalized request payload and SHA-256 payload hash, state, Auth/profile/school references, safe error code, attempt count/timestamps, recovery/cancellation actor/timestamps/channel, and an expiring worker lease. The payload check recursively rejects credential fields. Payload can contain necessary contact and school setup data while a request is pending/recoverable; it is cleared on completed or terminal outcomes. Retention and any eventual deletion policy require a separate privacy review.

The ledger stores no password, access/refresh token, service-role key, or bootstrap/operator secret. A unique request UUID is bound to operation, actor and exact normalized payload. Reusing it with different content is rejected. The SQL reservation uses an advisory lock and row lock; repeated matching requests return the same row and result. A 10-minute database lease serializes Edge workers through external Auth work. Each invocation's random lease token is its worker capability and must match the request's live lease. The worker renews every two minutes during external Auth operations. Claim, renew, Auth identity binding, profile creation and worker-driven state transitions lock the same ledger row and reject a missing, expired, superseded, cancelling or terminal lease. A crashed worker can be reclaimed after expiry; a stale worker can no longer bind an identity, create a profile, or change request state. Cancellation is accepted only from a reconciled failure/terminal state, so it cannot race an in-flight invite. If a request is still `pending` or `identity_created`, the operator resumes reconciliation first; the private identity-map lookup binds any orphan identity before cancellation is considered.

## State handling

```text
pending -> identity_created -> profile_created -> completed
   |              |                  |
   +--------------+------------------+-> failed_recoverable -> pending (operator/user retry)
   +--------------+--------------------> failed_terminal
   +-----------------------------------> cancelling -> failed_terminal(cancelled)
                                                  \-> failed_recoverable(compensation_failed)
```

**Superseded correlation design:** request identity is no longer inferred from `raw_user_meta_data` or a request marker in that user-editable field. Before an invite, the trusted Edge Function reserves the exact normalized email in `private.provisioning_identity_map` while holding the request's live lease. A `BEFORE INSERT` trigger on `auth.users` handles invite inserts (identified by `invited_at`), atomically binds the new Auth UUID, and writes a marker to server-controlled `raw_app_meta_data`; a normal signup/unrelated Admin API creation will not consume the intent. `lookup_provisioning_identity` returns only the immutable private mapping plus an `email_occupied` boolean. `record_provisioning_identity` requires the map and server-controlled Auth marker to agree with the request and exact email. User metadata mutation cannot change the map; a missing or conflicting map is never adopted. This design depends on a trigger against the managed Auth table and must pass the isolated Supabase Auth runtime before approval. Supabase documents that user metadata is user-editable and unsuitable for security-sensitive context ([Auth users](https://supabase.com/docs/guides/auth/users)); the invite API's `data` option maps to user metadata ([invite API](https://supabase.com/docs/reference/javascript/auth-admin-inviteuserbyemail)).

School creation, first administrator profile creation, audit row, and ledger `profile_created` result are committed by one PostgreSQL transaction. School-user profile, staff record where applicable, audit row and result are likewise one transaction. A lost Edge response is resolved by reading the committed ledger result. Database routines lock the request row, making concurrent requests with the same key idempotent. The owner routine also takes a global advisory lock and the existing partial unique index prevents two owner profiles even across distinct request IDs.

## Recovery behavior

The operator-only `provisioning-recovery` Edge Function accepts `status`, `resume`, and `cancel` by request ID. Status returns request ID, operation, state, record references, safe error code and timestamps, but not the payload, credentials, email, Auth UUID, or raw database error. A platform owner may resume ordinary requests only after SQL rechecks the original actor's current permission. Revoked permissions therefore stop a pending school provisioning operation.

Cancellation first locks the ledger request, confirms no profile exists and no live worker lease remains, and marks it `cancelling`; this prevents lease renewal and profile SQL from proceeding. If renewal and cancellation race, their row locks serialize them: renewal succeeds only while its token owns an unexpired non-cancelling request, and cancellation clears the token only after confirming it is no longer live. It deletes only the Auth identity returned by the private mapping after checking its UUID and exact expected email. If deletion times out, the function checks actual Auth state. The `cancelling` state is intentionally retryable: after a process crash, an operator can retry, observe that the identity is already absent, and finish cancellation. A failed Auth deletion is durably recorded as `failed_recoverable` with `compensation_failed` and can be retried. Cancellation never deletes a profile or school. A real PostgreSQL/Auth test must still exercise crash timing and concurrent finalizers.

For first-owner recovery before any platform owner exists, the operator token can inspect/resume/cancel only an `owner_bootstrap` request. Dedicated SQL recovery RPCs are service-role-only and rely on the Edge Function to validate the separate token. This is within the privileged server boundary and is a reason the token must be long, rotated, access-controlled, and never included in logs or browser code.

## Failure/retry test matrix

| Scenario | Intended durable behavior | Test coverage in this tree | Executed here |
|---|---|---|---|
| Auth invitation fails | Recoverable state; retry uses same key | Deno fake-service test | No; Deno unavailable |
| Invitation commits but HTTP result times out | Exact private request-to-Auth map recovers identity | Deno fake-service test; disposable Auth integration test | Not run for this remediation |
| Auth identity exists, profile transaction fails | Ledger keeps Auth UUID; retry does not invite another identity | Deno fake-service test | No; Deno unavailable |
| SQL transaction commits but Edge response is lost | Read `profile_created`/`completed` result; no second school/profile | Deno fake-service test | No; Deno unavailable |
| Auth compensation fails | Keep recoverable request; retry cancellation after checking actual Auth state | Deno fake-service test; SQL transition checks | Mock/static only; no PostgreSQL/Auth |
| Cancellation response is lost after Auth deletion | Retry observes missing identity and completes the existing `cancelling` request | Deno fake-service test; SQL retry path | No; Deno/PostgreSQL unavailable |
| Operator retries recoverable request | Same request resumes; actor permissions rechecked by SQL | Deno fake-service test; SQL grants/static checks | Static only |
| Identical requests arrive concurrently | Request row/advisory locks and worker lease avoid duplicate workers; transactional result avoids duplicate rows | Deno concurrent mock test; SQL locking/lease inspected | Mock/static only; no PostgreSQL |
| Different keys attempt first-owner creation | Global lock + unique partial index permits one; loser is recoverable/cancellable | Deno fake test; static index assertion | Mock/static only |
| Duplicate key with different payload/actor | Reject conflict; do not alter original row | Deno fake test; SQL payload binding inspected | Mock/static only |
| Existing email without matching private mapping | Do not adopt identity; terminal conflict/cancellation path | Deno fake test; SQL lookup design | Mock/static only |
| Unauthorized recovery | Owner permission or restricted operator token required | SQL privilege checks and source assertions | Static only |
| Lease renewal succeeds only for current worker | Renewal RPC locks row and checks exact request/token/expiry/state | Deno fake test added; SQL grant/source assertions | Static only; Deno/PostgreSQL not run |
| Expired, superseded, cancelling and terminal leases are rejected | Renewal checks the live lease and allowed states | Deno fake tests added | Not run |
| Renewal failure stops work and permits idempotent recovery | Worker fails closed before identity binding/profile RPC; runner records recoverable state if it still owns lease | Deno fake timeout/retry test added | Not run |

`supabase/tests/authentication-foundation-static.mjs` checks SQL declaration/ownership/revoke/grant signatures and key invariants, including the lease renewal RPC. `supabase/tests/authentication_foundation.sql` checks grants/RLS/function privileges against a database. `supabase/tests/provisioning-recovery.test.ts` exercises the Edge saga and lease behavior against a fake Supabase adapter. The fake-service suite is not a substitute for PostgreSQL/Auth integration, and no hosted request was made.

## Limitations and safety

- `psql`, `postgres`, `pg_ctl`, Docker, Supabase CLI and Deno are not installed/available in this environment. PostgreSQL parsing, transaction semantics, actual Auth invitation/deletion timing, JWT role behavior, RLS, advisory-lock/lease concurrency, and Deno tests remain unverified.
- Lease renewal and worker-token checks are proposed in the local authentication migration and Edge code, but remain unexecuted. They do not approve the rejected migration.
- `supabase/config.toml` signup restriction is local configuration only. Hosted public signup remains a separate manual setting and was not changed.
- Invited users may exist in Auth while the request is recoverable. They are not linked to application access until the atomic profile transaction commits; the application's Auth context denies unprovisioned identities.
- Existing Laravel password compatibility remains unchanged: this migration makes the legacy `users.password` nullable and the Laravel provider excludes null hashes. Existing password hashes are not synchronized with Supabase Auth.
- The payload persists necessary PII during recoverable operations. Define retention/expiry and operator monitoring before production use.
- The service-role credential can bypass these protections if disclosed. It must remain in Edge Function secrets only.

## Verification performed

- `node supabase/tests/authentication-foundation-static.mjs` — before the Stage 1B1 changes: 16 SQL functions and 48 ownership/privilege signature references passed. See `stage-1b-runtime-validation.md` for the updated Stage 1B1 result.
- PostgreSQL SQL test and migration — not run (no PostgreSQL/Supabase CLI available).
- Deno recovery tests — not run (Deno unavailable).
- Hosted Auth, recovery, concurrent requests, and cancellation — not run; no live Supabase calls were made.

## Approval status

**Not ready for production approval.** This is a local proposal with a mock test suite and static SQL checks only. It must receive independent security review and run against an isolated disposable PostgreSQL/Supabase environment before any production application. The rejected migration remains unapplied.
