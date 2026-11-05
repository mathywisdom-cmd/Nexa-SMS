# Nexa SMS — Final Pre-PostgreSQL Schema Review

**Review status:** source and SQL static review only. No Supabase project, database, PostgreSQL server/parser, Docker, or Supabase CLI was used. No SQL was applied. The proposed SQL remains a fail-closed schema draft with no application table grants or allow policies.

**Reviewed draft:** `supabase/migrations/20261104000000_nexasms_existing_schema_proposal.sql`  
**Source baseline:** all 35 files in `database/migrations/`, Laravel models/controllers/services and seed definitions, plus `docs/deployment/supabase-source-to-sql-verification.md`.

## Decision

**Ready for isolated PostgreSQL 17 DDL/syntax testing only, not production use or application cutover.** The source review issues below are reflected in the current draft. PostgreSQL execution, RLS behavior under the actual Supabase migration owner, data compatibility, and application behavior remain unverified. The first test must use a disposable empty PostgreSQL 17/Supabase test project, never the configured production project.

## 1. Student identity integrity

The six student-related relationships reference `student_records(school_id,user_id)`, backed by `UNIQUE(school_id,user_id)`, rather than accepting any `users.id` when a row has a school. The Laravel workflows select students from `StudentRecord` and then persist `StudentRecord.user_id`:

| Table | Source evidence | Draft relationship |
|---|---|---|
| `marks` | `MarkController` builds mark rows from student roster entries (`$s->user_id`). | Composite school/student FK to student profile; cascade |
| `exam_records` | Result records are built for students in the class/section roster; `MarkController` uses student user IDs. | Same; cascade |
| `payment_records` | `PaymentController` iterates student records and assigns `$st->user_id`. | Same; cascade |
| `attendance_records` | `AttendanceController` validates submitted IDs against the section roster before writing. | Same; cascade |
| `promotions` | `PromotionController` iterates student records and assigns `$st->user_id`. | Same; cascade |
| `pins` | `PinController::store()` generates a batch of unused PIN rows with only a code; `PinRepo::create()` sets the school while `student_id` remains NULL. PIN verification later assigns the user and student IDs. | Composite student-profile FK with cascade, plus a check requiring `school_id` once `student_id` is assigned. NULL student is supported for an unassigned PIN. |

`student_records` itself also has a composite FK to `users(school_id,id)`. The proposed SQL uses `MATCH SIMPLE` for that FK and retains the original scalar `user_id -> users.id` cascade, so schoolless legacy seed profiles still reference an existing user while school-owned profiles must point to a user in that school. For marks, exam records, payment records, promotions, pins, and attendance records, the SQL retains the source scalar `student_id -> users.id` cascade alongside the composite student-profile FK. Thus a NULL-school legacy row still has the original user-existence constraint; a non-NULL-school row additionally has the student-profile and same-school constraint.

The `ON DELETE CASCADE` behavior is consistent with the legacy user/profile delete chain: deleting a student user deletes their student profile and dependent rows. The additional same-school relationship constraints are stricter than the original scalar Laravel FKs, so existing imported data must be checked for orphan student IDs, student IDs without profiles, and school mismatches before adding constraints to a populated target.

Migration 30's schema addition is nullable, and its backfill assigns the migration-time existing rows to its bootstrap school before restoring `super_admin.users.school_id` to NULL. Normal authenticated school requests are assigned a school by `InitializeTenantContext`; `BelongsToSchool` stamps model creates when that context exists. However, the existing `DatabaseSeeder` runs after migrations and its direct inserts/factories do not establish tenant context. It creates NULL-school users, classes, sections, subjects, and student profiles. That is a concrete legacy seeding path, although those ownerless demo rows are not visible inside an active school tenant.

Accordingly, the five tables with nullable `school_id` and required student identity (`student_records`, `marks`, `exam_records`, `payment_records`, `promotions`) now use the default `MATCH SIMPLE` on their same-school composite FK. This preserves nullable ownership without changing the column declarations. For non-NULL school IDs the composite FK still enforces the same-school student profile. For NULL-school rows the composite is skipped, as PostgreSQL defines for MATCH SIMPLE; the restored scalar FK preserves the original user-existence constraint, but it cannot prove a student profile exists. Do not expose NULL-school rows through future RLS/API policies, and reconcile imported production data before enabling access.

`attendance_records.school_id` is NOT NULL in migration `2026_09_27_000001_create_academic_periods_and_attendance_tables.php`; its `MATCH FULL` student composite FK has no nullable-school conflict and remains. `pins.student_id` is nullable: `PinController::store()` and `PinRepo::create()` create unused school-owned PINs with no student, then verification assigns user/student IDs. The draft keeps its check requiring a school once a student is assigned and its MATCH SIMPLE composite profile FK, preserving that lifecycle.

## 2. Audit-log tenant integrity

### Laravel assignment behavior

`app/Services/AuditLogger.php` resolves the audit school as explicit `$schoolId`, then current `TenantContext`, then the authenticated actor's `school_id`. `actor_id` is the explicit actor or `Auth::id()`. Login/logout events pass the user's own school and ID in `app/Providers/AppServiceProvider.php`.

The platform school-management workflow in `app/Http/Controllers/SuperAdmin/SchoolController.php` explicitly writes the target school's ID while the acting `super_admin` profile has `school_id = NULL`. A composite `(school_id, actor_id)` FK or equality-only constraint would incorrectly reject these legitimate platform-owner events. System-generated audit rows can also have a NULL actor.

### Draft guard and remaining requirement

The draft's `private.check_audit_actor_school()` trigger checks that a non-platform actor has a non-null school matching `audit_logs.school_id`; it permits a `super_admin` to record an event against a school while remaining schoolless. An actorless row is allowed for trusted system operations. Direct table access is not granted to `anon` or `authenticated`, and RLS is forced with no allow policy.

This trigger is a **consistency guard, not an append authorization API**. Before application access is enabled, implement a narrow trusted append operation that derives `actor_id` from `auth.uid()` and the actor school from the trusted profile; it must validate `platform.*` permission before a platform actor can choose a target school. Only an explicitly privileged system path may append with a NULL actor. Do not trust browser-supplied actor IDs or school IDs. Keep direct client INSERT/UPDATE/DELETE on audit logs denied. The trigger's `SECURITY DEFINER` owner and `row_security=off` behavior still require the isolated PostgreSQL test described below.

Audit `school_id` and `actor_id` nullable behavior is legitimate: platform-wide/system entries and deletion `SET NULL` semantics exist in migration `2026_09_27_000002_create_audit_logs_table.php`.

## 3. Nullable school ownership

Migration `2026_09_27_000000_create_schools_and_assign_school_ownership.php` adds nullable `school_id` to all 24 tables below and never alters the columns to `NOT NULL`. It backfills rows existing at migration time to its historical default school and then resets `super_admin` users to NULL. The existing post-migration `DatabaseSeeder` is an exception to the backfill's result: it writes sample tenant records without school context. That does not establish a production default-school rule. Do not invent or assign a default school during this Supabase conversion.

| Legacy tenant table | Is NULL legitimate in normal operation? | Interpretation and target write rule |
|---|---|---|
| `users` | Yes, for platform owners; legacy `UsersTableSeeder` also creates ownerless sample users. | Normal school users require a real school. Keep nullable for platform owners and legacy seed parity; enforce school-user assignment in trusted provisioning logic. |
| `class_types` | No. | Legacy nullable schema/backfill artifact or missing tenant context. New school writes require a school. |
| `my_classes` | No. | Same. |
| `sections` | No. | Same. |
| `subjects` | No. | Same. |
| `student_records` | No. | Same; student profile must be school-owned. |
| `exams` | No. | Same. |
| `marks` | No. | Same; student composite FK also requires non-null tenant. |
| `exam_records` | No. | Same; student composite FK also requires non-null tenant. |
| `grades` | No. | Same. |
| `dorms` | No. | Same. |
| `pins` | No. | Same when associated with a student; new school-managed PIN rows require school ownership. |
| `books` | No. | Same. |
| `book_requests` | No. | Same. |
| `staff_records` | No. | Same. |
| `payments` | No. | Same. |
| `payment_records` | No. | Same; student composite FK also requires non-null tenant. |
| `receipts` | No. | Same. |
| `time_table_records` | No. | Same. |
| `time_slots` | No. | Same. |
| `time_tables` | No. | Same. |
| `promotions` | No. | Same; student composite FK also requires non-null tenant. |
| `settings` | No. | Same. |
| `skills` | No. | Same. |

The model trait `app/Models/Concerns/BelongsToSchool.php` fills `school_id` only when a tenant context exists; it does not reject a create without context. `InitializeTenantContext` normally establishes context for active/trial school requests, but seed/import/platform code can run outside that request flow. Therefore schema nullability must be preserved for legacy compatibility, while client-facing RLS `WITH CHECK` predicates and trusted write functions must require `school_id = private.current_school_id()` for school-owned rows. That equality rejects NULL for an authenticated school user. Trusted service operations must validate ownership explicitly rather than relying on RLS bypass.

Before importing data, classify every NULL school row by a verifiable source relationship. Migration 30 backfills preexisting rows, but post-migration seeders can create NULL rows in a subset of tables. Backfill only where an authoritative school mapping exists; quarantine/hold ambiguous rows for manual resolution. Do not infer a school from names, IDs, or a global default. `audit_logs.school_id` is outside these 24 tables and legitimately remains nullable for platform/system events.

Other composite FKs in the draft use PostgreSQL's default `MATCH SIMPLE`. When a nullable `school_id` is NULL, those composite constraints are skipped; when `school_id` is present, they enforce same-school references. Optional relationship columns also rely on SIMPLE semantics so a NULL optional parent is allowed. Every original scalar FK for these nullable-school relationships is now retained alongside the composite, preserving source existence and delete behavior when school_id is NULL. New target-only academic-period composites have no original scalar FK predecessor and still skip on NULL school; validate those new links during import. See the complete per-FK table in `supabase-source-to-sql-verification.md`.

**Unresolved:** when a student-related row has NULL `school_id`, its scalar source FK guarantees the `users` row exists, but the composite FK to `student_records` is skipped and cannot prove the profile exists. This matches the legacy database FK (student IDs referenced `users`) and supports the legacy seeder shape. If NexaSMS must require a `student_records` profile even for ownerless/import rows, choose an explicit reconciliation or null-safe trigger design before data import; do not infer a tenant or silently strengthen nullability.

## 4. Authorization helper functions and FORCE RLS

The draft defines `private.current_profile_id()`, `private.current_school_id()`, and `private.has_permission(text)` as `SECURITY DEFINER`, `STABLE`, with `SET search_path = ''` and `SET row_security = off`. Their table references are schema-qualified. The draft revokes execution from `PUBLIC`, `anon`, and `authenticated`, then grants `USAGE` on `private` and `EXECUTE` on only these helpers to `authenticated`; `anon` receives neither. The private schema is not added to an exposed API schema list in this SQL. Future deployment configuration must keep it unexposed.

The helper implementation uses `auth.uid()` and the `users.auth_user_id` link, not user-editable metadata. `has_permission()` applies the Laravel `PermissionService` platform-prefix check and per-user override precedence. `current_school_id()` additionally requires school status `active` or `trial`, matching `InitializeTenantContext` for ordinary application requests. RLS policies must still require the relevant permission and relationship; a non-null current school by itself is not authorization.

These helpers are **conditionally safe under FORCE ROW LEVEL SECURITY**. A security-definer function executes with its owner privileges. `row_security=off` does not grant bypass: if the function owner cannot bypass RLS, a forced-RLS query inside the helper can error rather than return its intended row. On the isolated Supabase target, verify the actual owner and that it has only the intended privileged lookup access (normally a controlled owner/BYPASSRLS context), then test calls as `anon`, a signed-in user without a profile, each role, and different schools with FORCE RLS enabled. Do not transfer ownership to an application role or grant broad table privileges to make tests pass. Since the helpers reveal only the current caller's profile/school/permission result, authenticated execution is narrowly scoped, but keep the schema private and never expose helper tables.

Important limitations: `current_profile_id()` can return a profile independent of school status; school-data policies should use `current_school_id()` as well. `has_permission()` alone does not check active school status or resource relationships. The SQL draft intentionally has no policies or table grants, so no browser table access is enabled yet.

## 5. Model-only fields

Static search across `app`, `resources`, `routes`, and migrations found these names only in model `$fillable` declarations; no controller, validation, view, report, service, or migration uses were found:

| Fields | Model | Classification | Decision |
|---|---|---|---|
| `student_records.wd`, `student_records.wd_date` | `app/Models/StudentRecord.php` | **Obsolete in current source** (fillable-only) | Do not add columns. Confirm against any external legacy database export before importing. |
| `exam_records.af_id`, `exam_records.ps_id` | `app/Models/ExamRecord.php` | **Obsolete in current source** (fillable-only); active model fields are `af` and `ps` | Do not add columns. Confirm against any external legacy database export before importing. |

This classification is based on repository source only; it cannot rule out undocumented columns in a separately maintained live database.

## 6. Role, permission, and user-type seed verification

The comparison included `database/seeders/UserTypesTableSeeder.php`, `config/user_types.php`, `database/seeders/SupportedUserTypesSeeder.php`, and all definitions/conditional inserts in migration `2026_09_27_000003_create_roles_and_permissions.php`.

### Complete `user_types` catalog represented

The union is 11 unique titles: `accountant`, `parent`, `teacher`, `admin`, `super_admin`, `student`, `librarian`, `principal`, `head_teacher`, `bursar`, `staff`. The SQL uses source labels and levels: Accountant/5, Parent/4, Teacher/3, Admin/2, Super Admin/1, Student/4, Principal/3, Head Teacher/3, Bursar/5, Librarian/6, Other Staff/6. `librarian` appears in both config and migration but is inserted once in the SQL. The legacy `UserTypesTableSeeder` has an old commented librarian row and does not seed student; the config's supported seeder supplies student and librarian when explicitly run.

### Roles, permissions, and grants

The SQL represents all 11 roles, all 32 permission names and descriptions, and migration 33 assignments. `super_admin` receives exactly the six `platform.*` grants; `admin` receives all non-platform permissions; principal, head teacher, teacher, accountant, bursar, librarian, parent, student, and staff assignments match the migration arrays. The platform-prefix guard in `PermissionService` is mirrored by `has_permission()`.

No application roles or permission names are added or renamed. This is static comparison only; row counts/grant sets were not queried against PostgreSQL. The SQL's role/permission reference rows are not demo school data.

## 7. SQL draft corrections and readiness risks

The SQL was corrected in this review to retain every one of the 47 original scalar FKs: 42 legacy relations have explicit scalar companions alongside school-scoped composites, four user lookup FKs and the LGA state FK remain unchanged, and the remaining later-migration FKs are represented as listed in the source-to-SQL report. The five nullable-school student composites use MATCH SIMPLE; attendance keeps MATCH FULL because its school_id is non-null in Laravel's migration. PIN lifecycle constraints were rechecked and retained. No application code was modified.

Before any populated import or application cutover, the isolated PostgreSQL 17 test must verify:

1. The full migration parses and applies, including composite `MATCH FULL` for non-nullable attendance ownership and PostgreSQL 17 column-targeted `ON DELETE SET NULL` clauses.
2. All historical records satisfy same-school FKs, with special checks for NULL school ownership and student IDs lacking a student profile.
3. The trigger and helper functions execute under the actual migration/function owner with FORCE RLS.
4. Helper calls are denied to anon and work only for the authenticated caller; `private` remains unexposed.
5. Audit writes preserve school-actor matching, allow a platform owner to audit a school, and cannot be forged by a browser role.
6. The exact role grants and denial behavior are tested role by role before any RLS allow policies are added.

### Files reviewed

- `supabase/migrations/20261104000000_nexasms_existing_schema_proposal.sql`
- `docs/deployment/supabase-source-to-sql-verification.md`
- All 35 `database/migrations/*.php` files, particularly `2026_09_27_000000_create_schools_and_assign_school_ownership.php`, `2026_09_27_000002_create_audit_logs_table.php`, and `2026_09_27_000003_create_roles_and_permissions.php`
- `app/Services/AuditLogger.php`, `app/Services/PermissionService.php`, `app/Http/Middleware/InitializeTenantContext.php`, `app/Tenancy/TenantContext.php`, `app/Models/Concerns/BelongsToSchool.php`
- Student, mark, exam, payment, attendance, promotion, PIN, and platform school controllers/models
- `database/seeders/UserTypesTableSeeder.php`, `database/seeders/SupportedUserTypesSeeder.php`, `config/user_types.php`

## Verification performed

- Static inspection of all 35 Laravel migrations and prior source-to-SQL review.
- Static source search for student ID writes and the four model-only fields.
- Verified the unused-PIN generation path and corrected its composite-FK null behavior without weakening assigned-student integrity.
- Static comparison of role, permission, and user-type definitions and SQL seeds.
- Static review of trigger/function security attributes, grants, and forced-RLS setup.
- Git branch/status review before edits.
- No automated tests, PostgreSQL parser, migrations, database queries, Supabase connections, Docker, or application code changes were performed.
