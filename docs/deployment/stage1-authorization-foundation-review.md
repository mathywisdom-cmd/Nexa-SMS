# NexaSMS Stage 1 Authorization Foundation

**Status: proposal prepared, not applied.** This work targets only the existing Supabase project `vyiyveqnsjyxrekbwdeg` (project name Nexa SMS). No migration was applied, no project settings were changed, no demo records were inserted, and no SQL writes were made to the project. The only remote queries were catalog reads, counts, function calls under a rolled-back transaction, and deliberately denied updates with `WHERE false`.

## Verified project state

- Project metadata identifies `Nexa SMS`, reference `vyiyveqnsjyxrekbwdeg`, PostgreSQL `17.11.0.011`, status `ACTIVE_HEALTHY`. The Velocity project was not queried.
- Supabase migration history contains `20261104164653 / nexasms_initial_schema_20261104`.
- The project has 40 public tables, all with RLS enabled and forced; current remote inspection found 0 public RLS policies and 0 `SELECT`/`INSERT`/`UPDATE`/`DELETE` table privileges for `anon` and `authenticated`.
- The authorization catalog has 11 roles and 32 permissions. Public app profiles, schools, and Auth users currently have zero rows. No user or school records were read or created.
- The live role/permission catalog separates `super_admin` as `platform` with six `platform.*` permissions; `admin` has 26 school permissions. The other observed seeded permission counts match the current catalog: principal 17, head teacher 10, teacher 9, accountant/bursar 5 each, librarian 2, staff 1, parent/student 0. These role grants are stored in `role_permissions`, not JWT metadata. Laravel also has a Super Admin role-permission update route (`PUT /super_admin/permissions/{role}` in the current route group); the Supabase replacement must preserve it through a trusted server operation because this stage deliberately denies direct browser writes to role catalogs.
- The security advisor reports 40 `rls_enabled_no_policy` informational findings. This is the expected closed state for this stage, but it means the app API is not yet usable.
- The `private` schema denies `anon` usage and grants `authenticated` usage. `current_profile_id`, `current_school_id`, and `has_permission` are executable by `authenticated`; `check_audit_actor_school` has no client execute grant. Public API exposed-schema settings could not be confirmed from the available database setting (`pgrst.db_schemas` returned NULL); verify that `private` is not included in the Dashboard's exposed schemas before later API work.
- The live `service_role` has SELECT/INSERT/UPDATE and permission-table INSERT privileges and is not a member of `authenticated`; `postgres` also has access. The proposal revokes only `public`, `anon`, and `authenticated`, preserving trusted service-role operations. Never expose the service-role key to a browser or Vercel client bundle.

## Existing identity and authorization implementation

The bridge is `public.users.auth_user_id UUID UNIQUE REFERENCES auth.users(id) ON DELETE CASCADE`. The three lookup helpers map the validated `auth.uid()` to this column; none reads `raw_user_meta_data` or trusts browser-provided `role`, `user_type`, or `school_id` claims. A unique bridge means one Auth UUID can map to at most one app profile. The actual project has no Auth users or app profiles, so a successful existing-user mapping cannot yet be demonstrated.

All four existing helpers are owned by `postgres`, `SECURITY DEFINER`, have an empty fixed `search_path`, and set `row_security=off`. That owner can read the forced-RLS profile/catalog tables. The lookup functions return only the caller's profile ID, active/trial school ID, or a boolean permission result. `has_permission` follows the Laravel `PermissionService`: profile `user_type` chooses the seeded role, per-user override takes precedence, and platform permission prefix is exclusive to `super_admin`. It does not itself verify active school status; future school policies must require both a non-NULL `current_school_id()` and the relevant `has_permission()` result. Platform policies must use the platform permission check without requiring a school context.

`auth.uid()` tests against a non-existent UUID returned: profile `NULL`, school `NULL`, and both `platform.schools.manage` and `students.view` as `false`. This ran on the actual project as `authenticated` inside a transaction that was rolled back.

Laravel's `App\\User` still uses session authentication. `user_type` is fillable and resolves through `Role.name`; `PermissionService` enforces `super_admin` platform separation. The Laravel app has no `auth_user_id` provisioning bridge. Migration to Supabase Auth therefore needs a future trusted server workflow and must not copy browser metadata into the profile.

## Verified weaknesses and boundaries

1. **Audit actor impersonation:** the existing `check_audit_actor_school()` enforces matching school for a school actor and allows `super_admin` cross-school audit rows, but it does not require `actor_id` to be the authenticated profile. If an insert policy/grant were later added without fixing this, a caller could attribute a same-school event to another person or name a platform owner. The proposed migration binds API audit inserts to `private.current_profile_id()` while preserving authenticated platform-owner actions against any school and trusted system rows with a NULL actor.
2. **Self-authority writes depend on the deny-all boundary:** current no-grant/no-policy state blocks user, role, school, Auth-link, and permission writes. There is no row-level guard for when later policies are added. The proposal adds a trigger preventing client-context profile creation and changes to `user_type`, `school_id`, or `auth_user_id`, and blocks client writes to role/permission catalogs and per-user overrides.
3. **Platform profile invariant is not constrained:** current SQL has no constraint requiring `super_admin.school_id IS NULL`, although Laravel migration 30 clears it and tenant middleware treats super-admin as platform-level. The proposal adds this check.
4. **Role referential validity:** `users.user_type` is not an FK to `roles.name` or `user_types.name`. The current role set is known, but adding that FK could change legacy/custom type behavior. It is not added in Stage 1; trusted provisioning must validate the requested type against the seeded role catalog.
5. **Audit mutability:** there is no append-only trigger for audit rows. Direct client grants are absent, so the current API remains closed. A blanket update/delete blocker is not added because deleting a referenced actor or school intentionally applies FK `SET NULL` updates to audit rows. Later audit policies/RPCs must deny client updates/deletes while preserving those FK actions.
6. **Auth deletion consequences:** the existing `auth_user_id → auth.users.id ON DELETE CASCADE` can delete the linked app profile and then cascade into profile-related application records. This proposal does not change it. Account deletion/recovery policy must review this behavior before real users are linked.
7. **Helper exposure configuration:** the database role grants are narrow, but project-level PostgREST exposed schemas could not be verified via SQL. Keep `private` out of exposed schemas; its helpers should be called only inside policies or trusted SQL paths.

## Proposed migration contents

The exact SQL for review is [20261104170421_stage1_authorization_foundation.sql](../../supabase/migrations/20261104170421_stage1_authorization_foundation.sql). It:

- Adds `users_super_admin_is_platform_only` (`super_admin` requires NULL `school_id`).
- Adds a `users` trigger that rejects client-context profile inserts and changes to role, school ownership, or Auth identity. It does not block ordinary profile-field updates by itself; RLS/column grants still must restrict those.
- Adds client-write guards to `user_permissions`, `role_permissions`, `roles`, `permissions`, and `user_types`.
- Replaces the audit trigger function so Auth API requests must use their own linked actor profile; a real super-admin retains platform-level cross-school audit behavior. Trusted service-role/system operations remain separate.
- Reasserts revocation of table privileges from `public`, `anon`, and `authenticated` on the identity/authorization tables.
- Adds no public policies and grants no general authenticated access.

### Potentially disruptive or irreversible effects

- Once applied, a `super_admin` row with non-NULL `school_id` would fail the new check. The live project currently has no user rows, so there is no existing row to invalidate; the check should remain before any profiles are provisioned.
- API clients and user-scoped `SECURITY DEFINER` functions will not be able to create profiles, change authority fields, or mutate permission catalogs. This is intentional; a future Edge/server provisioning path must authenticate and authorize those operations explicitly.
- Audit inserts from authenticated API requests must carry their own profile ID. Existing Laravel owner workflows do so through `Auth::id()` and pass a target school separately; the platform-owner exception is retained. Service-role handling must never expose its key to the browser.
- No existing `auth.users → users → school data` cascade is changed. Auth account deletion may still delete profile-related records.
- The migration is additive/reversible by dropping its triggers, function(s), and check constraint, but do not roll back after real accounts depend on the guards without an approved replacement policy.

## Security test artifact and actual-project results

[stage1_authorization_foundation.sql](../../supabase/tests/stage1_authorization_foundation.sql) is a transaction-wrapped test script for after the migration is approved and applied. It inserts no fixtures. It checks the 40-table forced-RLS/no-policy/no-grant boundary, helper fail-closed behavior for an unlinked UUID, and denied direct identity/role/permission writes.

Actual-project baseline checks performed before this proposal:

- Unlinked UUID helper result: passed (`NULL`, `NULL`, `false`, `false`).
- `authenticated` direct update of `public.users` role (`WHERE false`): denied with SQLSTATE `42501 permission denied for table users`.
- `authenticated` direct update of `public.user_permissions` (`WHERE false`): denied with SQLSTATE `42501 permission denied for table user_permissions`.
- Public table privileges: 0 for both anon and authenticated; public policies: 0; forced-RLS public tables: 40.
- Positive Auth-to-profile resolution and two-school allow/deny behavior: **not testable yet** because the project has zero Auth users, profiles, and schools, and the instruction prohibits creating fixtures. Current isolation is total API denial, not working school-level row access.
- Proposed trigger behavior: not tested against the live schema because the migration has not been applied. The test artifact is prepared for after approval; do not treat it as already passed.

## Approval boundary

No SQL has been applied. Before any schema change, review the exact proposed migration above. Await explicit approval to apply it to `vyiyveqnsjyxrekbwdeg`. After approval, first apply this single migration, then run its SQL verification script and re-check advisors. Positive multi-school policy tests must wait until the project has legitimate, explicitly provisioned test identities/schools or a separately approved safe test strategy; Stage 1 intentionally has no allow policies.

## Revision 2 — service-role, profile fields, and test coverage

This section supersedes the earlier wording about how the trusted-server exception and tests work. The revised proposal and verification artifact remain local and unapplied.

### Trusted provisioning and service-role claim

The earlier `auth.uid() IS NOT NULL` check would reject a service-role request carrying a `sub` claim. Supabase documents that the signed JWT `role` claim selects the Postgres role used by the Data API, and that `service_role` is the elevated API role ([database roles](https://supabase.com/docs/guides/database/postgres/roles), [custom claims and RBAC](https://supabase.com/docs/guides/api/custom-claims-and-role-based-access-control-rbac)). A rollback-only target-project simulation reported `current_user=service_role`, `session_user=postgres`, and `current_setting('role')='service_role'` after `SET LOCAL ROLE service_role`; with no JWT GUCs, `auth.role()` and `auth.uid()` were NULL. A second rollback-only simulation set synthetic request claim GUCs and returned `current_user=service_role`, the same effective role setting, `auth.role()='service_role'`, and a non-NULL synthetic `auth.uid()`. This demonstrates how role/subject claims and the effective database role appear together; it is not signature validation of a real JWT. No service key or real user token was read.

The guards now accept a service operation only when the `service_role` claim is accompanied by an effective `service_role` database context (`current_user`, `current_setting('role')`, or `session_user`). The audit SECURITY DEFINER trigger checks the role setting/session identity because its `current_user` is its owner (`postgres`). A forged `request.jwt.claim.role` GUC from an `authenticated` context does not satisfy the effective-role condition. The database test artifact explicitly checks this forged-GUC denial and a service-role profile/role-assignment success with non-NULL `auth.uid()`.

The script has not been run against a database after the proposed migration, so the behavior of `current_setting('role')` inside the SECURITY DEFINER trigger is an important pending execution check. The script exercises it with a temporary audit table and must pass before later RLS work. No signed real service JWT was inspected; the JWT role/sub simulation is prepared in the verification script and remains unexecuted.

### `public.users` identity and authorization fields

Source inspection of the actual Laravel login controller and model confirms email-or-username login. The legacy `users` row has no independent active/suspended column; school `status` (`active`, `trial`, inactive/suspended values) gates tenant access in `InitializeTenantContext`.

The revised guard protects updates to `id`, `code`, `username`, `email`, `password`, `remember_token`, `email_verified_at`, `user_type`, `school_id`, and `auth_user_id`. The first seven fields affect identity, credentials, or verification; `user_type` is read by Laravel `PermissionService`; `school_id` controls tenant context; `auth_user_id` binds the profile to Supabase Auth. It rejects nontrusted profile inserts and deletes too. Other personal/profile fields remain for future narrowly scoped RLS and column grants; they do not independently decide account activation, tenant ownership, or role authorization in the inspected code. The school guard protects school creation/deletion and changes to `id`, `code`, `slug`, and `status` (tenant identity/routing and activation). No policy or table grant is added.

### Audit trigger, helper-function properties, and execution grants

Fresh target-project catalog inspection verified the current audit trigger exactly:

| Field | Verified value |
|---|---|
| Table | `public.audit_logs` |
| Trigger name | `audit_logs_actor_school_guard` |
| Definition | `BEFORE INSERT ON public.audit_logs FOR EACH ROW` |
| Enabled state | `O` (origin/enabled) |
| Bound function | `private.check_audit_actor_school()` |

Fresh catalog results also show the existing four helper functions are owned by `postgres`, `SECURITY DEFINER`, and configured with `search_path=""`, `row_security=off`. `authenticated` can execute `current_profile_id`, `current_school_id`, and `has_permission`; `anon` cannot. Neither client role can execute `check_audit_actor_school`. The proposal preserves the audit function's owner and explicitly sets it to `postgres`, fixed empty search path, and `row_security=off`. New write guards are `SECURITY INVOKER`, owner `postgres`, fixed empty search path, and revoked from `PUBLIC`, `anon`, and `authenticated`; they are only reached through triggers. They do not receive client EXECUTE grants.

The migration preflight now also aborts unless the existing helpers are owned by `postgres`, remain SECURITY DEFINER with fixed empty search path and `row_security=off`, and retain the expected narrow execute grants (`authenticated` only for the three lookup/permission helpers; no client/service execute grant for the audit trigger function). It checks trigger name, target table, function OID, timing/event bits, row scope, and enabled state. If an expected named trigger is absent, it creates only that trigger. If it already exists but differs, the migration raises an exception and does not replace it. The existing audit trigger is validated before its function body is replaced; no second audit trigger is created.

### Audit actor rules and system writes

Authenticated audit events must use the profile mapped from the signed subject. A service-role request with a linked subject must use that profile. During provisioning, a subject that is not linked yet may append only with `actor_id IS NULL`; it cannot attribute the event to an unrelated profile. A service-role system request without a subject may also append only with `actor_id IS NULL`. School actors must still write only a matching `school_id`; a stored `super_admin` profile may audit another school, preserving the Laravel behavior where platform operations use the platform actor and a target school. The live project has no users/schools, so school/profile positive cases cannot be executed without fixtures and are conditionally skipped in the SQL artifact.

### Preserving Laravel role-permission updates

Laravel's existing `PUT /super_admin/permissions/{role}` is protected by the `super_admin` middleware (`platform.schools.manage`). `RolePermissionController::update` validates permission names, checks that permission scope matches the role scope, syncs role-permission rows in a transaction, and records an audit event. The new triggers block direct client writes while allowing a server-side service-role operation. A later Edge Function/RPC must authenticate the caller, verify the mapped profile has `platform.schools.manage`, repeat the scope check against stored role/permission rows, update the mapping transactionally, and append the audit event with the validated caller profile. The service-role key must remain server-only. No replacement update endpoint or broad grant is included in this stage.

### Verification artifact and results

`supabase/tests/stage1_authorization_foundation.sql` now checks exact trigger bindings and function attributes; no client grants/policies; profile/school/authorization write guards on temporary tables; sensitive-column rejection; ordinary profile-detail updates; forged service-role GUC denial; service-role provisioning and role-assignment success when `auth.uid()` is non-NULL; actorless system audit behavior; audit actor impersonation; own-school audit success; cross-school audit denial; and platform super-admin cross-school audit success when legitimate linked records already exist. All work is transaction-wrapped and rolled back; no permanent fixture rows are defined.

**Executed after this revision:** only read-only target catalog/role checks and a rolled-back service-role context simulation. **Not executed:** the proposed migration, the revised verification SQL, or any positive audit behavior against linked users/schools. The target has zero Auth users, profiles, and schools, and no local PostgreSQL/Docker test is permitted. Do not describe the proposal as runtime-tested or RLS-ready. The existing no-policy/no-grant boundary remains closed; this stage does not enable client application access.

### Change list from the prior proposal

- Replaced the `auth.uid() IS NOT NULL` incompatibility with a service-role claim plus effective-database-role check, including the SECURITY DEFINER audit-trigger context.
- Added a forged custom-role-GUC negative case so claim text alone cannot be used as a client bypass.
- Bound service-role audit events with a user subject to that subject's profile; system service events without a subject require a NULL actor.
- Expanded profile protection to all inspected identity/credential/verification/role/tenant fields and blocked untrusted profile deletion; added school deletion protection alongside status/routing guards.
- Explicitly assigned new guard and audit trigger functions to `postgres`; retained fixed search path and added migration-time checks for the existing SECURITY DEFINER helpers' ownership, settings, and execute grants.
- Made duplicate trigger creation safe by checking the exact existing trigger definition and enabled state; mismatches abort without overwrite.
- Expanded the SQL verification script to test positive/negative guard behavior using temporary tables, and conditional real-profile audit scenarios without creating persistent fixtures.
- Documented Laravel role-permission update preservation through a narrowly authorized trusted server workflow.

### Rollback plan (only if this migration is later approved and applied)

The migration adds no data rows and grants no client access. Rollback must be coordinated with later policies/functions because restoring the older audit behavior removes actor-to-JWT binding. In one controlled transaction: drop only the exact Stage 1 triggers named below; restore the original audit function body from the initial schema migration; drop the three newly introduced guard functions; and drop `users_super_admin_is_platform_only`. Do not drop or recreate `audit_logs_actor_school_guard`; it predates this migration and remains bound to the restored original function.

```sql
begin;
drop trigger if exists users_guard_authority_fields on public.users;
drop trigger if exists schools_guard_authority_fields on public.schools;
drop trigger if exists user_permissions_guard_client_writes on public.user_permissions;
drop trigger if exists role_permissions_guard_client_writes on public.role_permissions;
drop trigger if exists roles_guard_client_writes on public.roles;
drop trigger if exists permissions_guard_client_writes on public.permissions;
drop trigger if exists user_types_guard_client_writes on public.user_types;

alter table public.users drop constraint if exists users_super_admin_is_platform_only;

create or replace function private.check_audit_actor_school() returns trigger
language plpgsql security definer set search_path = '' set row_security = off as $$
declare actor_school integer; actor_type text;
begin
  if new.actor_id is null then return new; end if;
  select u.school_id, u.user_type into actor_school, actor_type
    from public.users u where u.id = new.actor_id;
  if not found then raise exception 'audit actor does not exist'; end if;
  if actor_type = 'super_admin' then return new; end if;
  if actor_school is null or new.school_id is distinct from actor_school then
    raise exception 'audit actor school does not match audit school';
  end if;
  return new;
end
$$;
alter function private.check_audit_actor_school() owner to postgres;
revoke all on function private.check_audit_actor_school() from public, anon, authenticated;

drop function if exists private.guard_profile_authority_fields();
drop function if exists private.guard_school_authority_fields();
drop function if exists private.guard_authorization_catalog_writes();
commit;
```

Before running this rollback, compare grants with the known baseline (currently no `anon`/`authenticated` table grants on these tables). If any later authorized workflow or policy depends on the new guard, stop and design a coordinated rollback instead of executing this script. No rollback has been run.

## Revision 3 — original-session trust and explicit privilege boundary

This section supersedes the previous revision's trusted-server expression and role-context test descriptions. The migration and verification SQL remain proposals; neither was applied or executed.

### Trust detection after `SECURITY DEFINER`

PostgreSQL documents that `current_user` is the effective permission-checking identity and changes during `SECURITY DEFINER`; `session_user` remains the connection identity. Supabase documents that `authenticator` is the PostgREST connection role and PostgREST changes into the role selected by a verified JWT `role` claim ([PostgreSQL system identity functions](https://www.postgresql.org/docs/current/functions-info.html), [Supabase Postgres roles](https://supabase.com/docs/guides/database/postgres/roles), [Supabase JWT claims](https://supabase.com/docs/guides/auth/jwt-fields)).

The corrected audit trigger does not use `current_user` to decide whether the original caller was a database owner. It uses:

- **Service API request:** JWT role is `service_role`, original `session_user` is `authenticator`, and `current_setting('role')` is `service_role`. A direct `service_role` database session is also accepted when its role setting is `none` or `service_role`. A service-role request may have a non-NULL subject; the audit branch still requires a linked subject to use its own profile, while an unlinked provisioning subject can only emit a NULL-actor event.
- **Owner/migration request:** JWT role and subject are absent, original `session_user` is `postgres` or `supabase_admin`, and the selected role setting is `none` or that same owner. When invoked by the SECURITY DEFINER trigger, `current_user=postgres` alone has no authority to satisfy this branch.
- **Authenticated/anon request:** it fails both branches. Missing or forged `request.jwt.claim.role` text alone does not change the effective role. The target project reports `authenticated` and `anon` do not have `SET` membership in `service_role`; PostgREST's `authenticator` does, so it can perform the server-side role switch after JWT validation. This membership check is supporting evidence, not proof against a deliberately exposed SQL/RPC function that changes role or claim GUCs. The live project currently has no public-schema functions executable by `anon` or `authenticated`, and Stage 1 adds none. Future public RPCs must not expose `SET ROLE`, `set_config('role',...)`, or JWT-claim GUC mutation paths.

The three SECURITY INVOKER guards also require `current_user` to match the effective service role, plus the original session identity. Their owner branch requires both `session_user` and `current_user` to remain an owner. Thus the old `jwt_role IS NULL AND auth.uid() IS NULL AND current_user IN (...)` condition has been removed from all four functions. The SECURITY DEFINER audit function never uses its own `current_user` to identify the caller; it compares the request role claim with the original `session_user` and selected `role` setting.

### Actual project role-context observations

Read-only, rollback-contained target-project queries used `SET LOCAL ROLE` under the SQL editor's owner connection. These are actual PostgreSQL role-context observations, but they are simulations of the API role switch, not real PostgREST requests or signed JWT tests.

| Simulated role | `current_user` | `session_user` | `current_setting('role')` | `auth.role()` / `auth.uid()` |
|---|---|---|---|---|
| Owner baseline | `postgres` | `postgres` | unset/`none` | NULL / NULL |
| `anon` | `anon` | `postgres` | `anon` | NULL / NULL |
| `authenticated` | `authenticated` | `postgres` | `authenticated` | NULL / NULL |
| `service_role` | `service_role` | `postgres` | `service_role` | NULL / NULL |

Separate synthetic-claim simulations showed `auth.role()='service_role'` and a synthetic non-NULL `auth.uid()` when the role was set to `service_role`. They only verify the database's GUC readers; they do not verify JWT signatures. Role membership checks returned `authenticated -> service_role: SET false`, `anon -> service_role: SET false`, and `authenticator -> service_role: SET true`. PostgreSQL checks `session_user` for `SET ROLE`, so these catalog values alone do not prove how a deliberately callable RPC would behave in an authenticator session. A follow-up attempt to use `SET SESSION AUTHORIZATION authenticator` through the connected SQL tool was rejected with `permission denied to set session authorization`; it therefore did not test the API session. No authenticated or anonymous JWT was available in the project: `auth.users`, `public.users`, and `public.schools` each contain zero rows. The real `session_user=authenticator` API path, a signed service JWT, and the trust logic inside a live SECURITY DEFINER invocation remain untested until an approved API-level verification is run. No real JWT test is claimed as passed.

### Constraint diagnostic

The migration now checks `pg_catalog.pg_constraint` for `users_super_admin_is_platform_only` before adding it. If that name already exists on `public.users`, it aborts with a clear instruction to inspect the existing constraint. It does not silently accept, replace, or overwrite it.

### Explicit table revocations

The Stage 1 migration explicitly runs `REVOKE ALL ... FROM public, anon, authenticated` on these seven tables only: `users`, `schools`, `user_permissions`, `role_permissions`, `roles`, `permissions`, and `user_types`. The initial schema migration explicitly revoked those roles from all 40 public application tables. The other 33 tables rely on the initial migration's revocations; Stage 1 does not repeat them. The updated verification script checks effective `has_table_privilege` for all public tables, which catches inherited `PUBLIC` grants as well as direct grants. `service_role` grants are not revoked.

The rollback script does not restore table grants. At the verified pre-Stage-1 baseline there were no effective `anon`/`authenticated` table privileges, and the initial schema migration's revocations remain in place. Re-check grants before rollback if later migrations have changed them.

### Updated test and rollback limits

The SQL verification script now explicitly requires an owner session to run, asserts the observed `session_user` and effective `current_user`/`role` for owner and simulated anon/authenticated/service-role contexts, tests missing and forged role-claim settings, checks role `SET` memberships, and exercises the trusted owner and service-role paths on temporary tables. Its `SET ROLE service_role` case is labeled a SQL-editor simulation with synthetic claims; it is not a signed JWT/API test. The owner audit test clears all synthetic claims and invokes the SECURITY DEFINER trigger with a NULL/system actor. It conditionally tests linked school actor, cross-school, and platform-owner audit cases only when real records already exist. Current project state causes those profile-dependent cases to skip. Future RPC reviews must reject callable paths that change effective roles or request claim GUCs before protected writes.

### Rollback note for this revision

This remains an unapplied proposal, so there is no live migration to roll back. The verification-script edits are transaction-contained and end in `ROLLBACK`; the script itself has not been run. If the proposal is approved and applied later, use the rollback transaction documented above: remove only the seven named Stage 1 triggers, restore the original `private.check_audit_actor_school()` body, drop the three new guard functions, and drop `users_super_admin_is_platform_only`. Keep the existing audit trigger and initial 40-table revocations. Verify grants and check for dependent policies/workflows before any rollback; stop if later authorization work depends on Stage 1.

The rollback remains the prior transaction in this document: drop only the named Stage 1 triggers, restore the initial audit function body, drop the three new guard functions and the added check constraint. It does not drop/recreate the pre-existing audit trigger or reverse the initial schema migration's 40-table revocations. If any subsequent policy/workflow depends on Stage 1, stop and design a coordinated rollback.
