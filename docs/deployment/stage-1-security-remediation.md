# Nexa SMS Stage 1 Security Remediation

**Status: local proposal; not approved for hosted application.** No hosted database, Auth setting, Edge Function, or Vercel project was changed. No local tests were run as required.

## Corrections made

### One-time platform-owner bootstrap

- `private.owner_bootstrap_credentials` stores only a SHA-256 verifier and expiry. Raw tokens are not written to PostgreSQL.
- `private.arm_owner_bootstrap_credential` is database-owner-only, rejects expiry beyond 24 hours, refuses to arm after an owner exists, and permits rotation before consumption. A consumed credential can be replaced only when its linked request is explicitly terminal-cancelled and has no profile.
- `public.reserve_owner_bootstrap_request` serializes competing bootstrap attempts by locking the singleton credential row. One request ID consumes it; same-request retries may resume while incomplete, but changed payloads, different request IDs, and successful-bootstrap replays are rejected. `users_single_platform_owner` remains an additional unique constraint.
- `consume_owner_bootstrap_rate_limit` throttles to five attempts per source fingerprint in 15 minutes; hashes, not raw source addresses, are logged. Security events record rejected, expired, replayed, and lifecycle outcomes.
- The edge handler also checks a configured token expiry. After bootstrap succeeds, remove the bootstrap token from Edge Function secrets. Recovery credentials must also have an expiry and be rotated or removed when no longer needed.

Operator procedure after the migration has passed isolated review: generate a cryptographically random token outside the repository, retain it in a secret manager, calculate its SHA-256 verifier locally, and call `private.arm_owner_bootstrap_credential(verifier, expiry)` in the existing Nexa SMS project as the database owner. Install the raw token and matching expiry only as `NEXASMS_OWNER_BOOTSTRAP_TOKEN` and `NEXASMS_OWNER_BOOTSTRAP_TOKEN_EXPIRES_AT` Edge secrets. After the bootstrap result is verified, remove the Edge token/expiry. If the request failed recoverably, use the short-lived provisioning operator token to resume the same request; do not rotate the consumed bootstrap verifier until cancellation has completed and the DB owner procedure permits re-arming. Never place the raw verifier/token in SQL history, a shell transcript, GitHub Actions output, or browser configuration.

The bootstrap source fingerprint uses the platform `cf-connecting-ip` request header. The hosted ingress must overwrite this header; if this cannot be confirmed for the deployed function path, use a platform-supported trusted client-IP source or an upstream rate limiter before enabling bootstrap.

### Auth identity correlation

- `private.provisioning_identity_map` binds one request to one normalized email and later one Auth UUID. It is RLS-enabled and forced, and has no direct grants to browser or service roles.
- Before calling Auth invite, a worker with a live lease reserves the exact email intent. The `auth.users` `BEFORE INSERT` trigger handles invitation inserts (`invited_at` is set), binds the new UUID inside the Auth row transaction, and writes a marker to server-controlled `raw_app_meta_data`. An ordinary signup or unrelated Admin API create for the same email does not consume the intent.
- Recovery and timeout reconciliation read the private map and verify the Auth UUID/email. `raw_user_meta_data` is not consulted. `record_provisioning_identity` requires the map and server-controlled `raw_app_meta_data` marker to match.
- Existing email without a matching map is treated as occupied and is never adopted. Missing marker/map and email mismatch stop profile creation.

Supabase documents that `user_metadata` is editable by the user and must not be trusted for sensitive authorization decisions ([Auth users](https://supabase.com/docs/guides/auth/users)). Its `inviteUserByEmail` `data` option is stored in `user_metadata` ([invite reference](https://supabase.com/docs/reference/javascript/auth-admin-inviteuserbyemail)). Supabase's Auth user representation includes `invited_at` for invited identities ([Auth server reference](https://supabase.com/docs/reference/self-hosting-auth/get-a-user)). This proposal avoids invite metadata and correlates through the trusted DB trigger. Supabase warns that a failing Auth trigger can block Auth user creation ([User management](https://supabase.com/docs/guides/auth/managing-user-data)). The trigger on managed `auth.users` must pass isolated Supabase Auth integration before any hosted approval.

Trusted server-side Admin API creation can set `app_metadata`; the SQL fallback accepts such an identity only when the server-controlled marker and request/email match. Browser clients cannot set `app_metadata`. Keep service-role credentials exclusively in Edge Function secrets.

### Bounded request bodies

All five endpoints use a shared streamed JSON reader. Per-route limits are:

| Function | Maximum body |
|---|---:|
| `bootstrap-owner` | 8 KiB |
| `provision-school` | 16 KiB |
| `provision-school-user` | 12 KiB |
| `provisioning-recovery` | 4 KiB |
| `login-username` | 4 KiB |

The reader checks declared size early and also counts actual streamed bytes, cancels an oversized stream, and returns consistent errors for missing, empty, malformed, invalid-content-type, and oversized bodies. Deno tests and workflow steps cover those cases; they have not run for this remediation.

## Hosted runtime and recovery

Supabase currently documents 150 seconds wall time on Free, 400 seconds on paid plans, and a separate 150-second request idle timeout ([Edge Function limits](https://supabase.com/docs/guides/functions/limits)). The SQL provisioning lease lasts ten minutes and is renewed while an operation runs, but it does not extend the HTTP response deadline. A timed-out request can leave an operation progressing; callers should use the durable request ID to check status and resume safely. If normal Auth operations approach the idle limit, split the workflow into short request/resume calls driven by a durable external queue or client polling. This remediation adds no background processing system.

The lease permits another worker to recover after expiry. Renewal, identity binding, profile creation, cancellation and state transitions remain tied to the exact request and lease token. An expired worker cannot mutate the request; an interrupted invite is reconciled from the private identity map.

## CI validation coverage authored

- Concurrent HTTP bootstrap requests; only one request may create an owner.
- Successful-bootstrap replay using the same key and a different email.
- Expired credential rejection and verifier rotation before consumption.
- SQL lifecycle test for refusing rotation of an active consumed request and allowing rotation after explicit terminal cancellation.
- Operator-token expiry and rotation.
- Auth invitation timeout with user metadata mutation, exact identity reconciliation, and missing server-controlled correlation denial.
- Streamed and declared oversized bodies, malformed/empty/missing body, and unsupported content type.
- Existing integration cases for provisioning permissions, tenant isolation, audit trigger, lease renewal, retry, compensation, and Laravel password compatibility remain in the workflow.

These are **tests authored, not executed**. No test command was run in this environment. The isolated workflow has not run after these changes. It targets disposable local Supabase services in GitHub Actions and does not target hosted project `vyiyveqnsjyxrekbwdeg`.

## Remaining risks and deployment gates

1. Independent review is required for the migration and Auth trigger.
2. Run the isolated GitHub Actions workflow and inspect every job/log before approval.
3. Verify hosted Auth signup restrictions, redirect URLs, SMTP, Edge secrets, CORS, and trusted proxy IP header manually. None were changed or verified.
4. Validate real hosted timeout behavior after isolated review; local CI cannot prove hosted limits or invitation-email delivery.
5. Confirm recovery/operator token expiry, access control, rotation, and removal procedures with the operator.
6. Rollback is not a simple down migration. Dropping the trigger/map/credential tables while requests or Auth identities are pending can strand identities; changing `users.password` nullability can conflict with other Laravel writers. The migration remains rejected and should only be applied after a coordinated rollback/data-preservation plan.

## Files in this remediation

The exact local diff is the authority for changed paths. It includes the authentication proposal, five Edge Function paths/shared helpers, isolated SQL/Deno tests and workflow, and this plus related deployment documentation. Existing unrelated frontend, package, and audit work remains present and uncommitted.
