# Supabase schema and data migration plan

**Status:** design only. No SQL migration was written or executed. Existing Laravel migration history is left intact.  
The target design must be generated as new Supabase migrations after schema review and PostgreSQL compatibility testing; do not blindly translate Laravel migration syntax or apply this plan to a live project.

## Target identity and tenant model

1. Supabase Auth owns credentials, password hashes, verification, recovery tokens and sessions in `auth.users`.
2. Keep an application profile table (initially reuse `public.users` or create `public.profiles`) with existing integer `id` to preserve legacy references and an `auth_user_id uuid UNIQUE REFERENCES auth.users(id)`. Do not replace every legacy user FK with UUID until all app relationships and import mappings are designed. Remove/retire `password` and Laravel reset-token fields from exposed application data after cutover.
3. Preserve `public.schools` with stable IDs, unique `code`, unique `slug`, status and school settings. Add a membership relation if accounts can have multiple school memberships; for strictly single-school accounts, a constrained `school_id` on the profile can represent membership. For now design `school_memberships(user_id, school_id, role_id, status, created_at)` so RLS can reason about membership explicitly and later multi-school access does not depend on mutable client claims.
4. Keep global `roles`, `permissions`, `role_permissions`; move effective per-user overrides into membership-scoped `user_permissions` (or explicitly constrain existing overrides to the same school). Existing `users.user_type` values map to roles but remain during data reconciliation.
5. Platform owner is an explicitly provisioned platform-scoped membership/role, not any browser-set claim. No tenant data access by default. School user access comes from a verified membership; membership/role changes are never self-service client writes.

### RLS policy shape (design only)

- Enable RLS on every exposed `public` application table; grant only necessary `SELECT/INSERT/UPDATE/DELETE` actions to `authenticated`.
- School-owned rows: policy requires `school_id` to match an active `school_memberships` row joined through `auth_user_id = auth.uid()`; platform-role policies are separate and limited to platform functions/owner paths. `TO authenticated` alone is not authorization.
- `INSERT` uses `WITH CHECK`; `UPDATE` uses both `USING` and `WITH CHECK` so a client cannot reassign `school_id`; deny client writes to `school_id`, role, permission, ownership, account status and audit actor fields unless a specific safe workflow handles them.
- Cross-row constraints: include `school_id` in unique constraints and composite foreign keys where practical (class/section/student/term links) so one tenant cannot connect a row to another tenant's record.
- Policy checks should use membership rows readable by the current user; avoid recursive RLS and avoid `SECURITY DEFINER` as a shortcut. If a helper function is unavoidable, put it in an unexposed schema, explicitly verify `auth.uid()`, set a safe `search_path`, restrict `EXECUTE` grants, and test under actual `anon`/`authenticated` roles. Never use editable `user_metadata` for authorization. Treat `app_metadata` JWT role claims as potentially stale until token refresh.
- PostgreSQL views can bypass RLS by default; use `security_invoker` on supported PG versions or keep restricted views in an unexposed schema and grant narrowly.

Supabase security references: [RLS](https://supabase.com/docs/guides/database/postgres/row-level-security), [Auth SSR/session guidance](https://supabase.com/docs/guides/auth/server-side/creating-a-client?framework=nextjs), [Storage access control](https://supabase.com/docs/guides/storage/security/access-control).

## Tables and relationship map

The 35 Laravel migrations create/alter the following 40 tables. Table list is derived statically from `database/migrations/**`; relationships below are the declared/observed FK intent, not a PostgreSQL execution result.

| Domain | Tables and target notes |
|---|---|
| Platform/tenant | `schools` → root tenant; `settings` tenant-owned key/value configuration; `audit_logs` tenant nullable for platform events. `schools` has unique `code`, later unique `slug`; status/timezone/currency defaults. |
| Auth/profile/roles | `users` profile and legacy IDs; `password_resets` retire in favor of Supabase Auth; `user_types` legacy role labels; `roles`, `permissions`, `role_permissions`, `user_permissions` (scope overrides by membership). User references include student/parent/teacher/staff rows. |
| Shared reference catalogs | `states`, `lgas` (state FK), `nationalities`, `blood_groups`. Shared/read-only unless a future district scope is explicitly added. |
| Classes and people | `class_types`, `my_classes`, `sections`, `subjects`, `student_records`, `staff_records`, `dorms`. References: class↔type; section→class/teacher; subject→class/teacher; student record→user, class, section, parent, dorm; staff record→user. |
| Academic / assessment | `academic_years`, `terms`, `exams`, `grades`, `marks`, `exam_records`, `skills`, `promotions`, `pins`. References include term→year; class/type; exam/year/term; marks and exam records→student/class/section/subject/exam/grade; promotions→student and from/to class/section/year; PIN→users/student. |
| Attendance | `attendance_sessions`, `attendance_records`. Session→school/class/section/year/term/marker; record→school/session/student. Unique class+section+date session and unique session+student record constraints. |
| Fees/payments | `payments`, `payment_records`, `receipts`. Payment records→payment and student; receipt→payment record. Add school-composite FK and idempotency/amount integrity appropriate for payment workflows. |
| Library | `books`, `book_requests`; book→class, request→book/user. Add school-composite FK and borrower/type constraints. |
| Timetable | `time_table_records`, `time_slots`, `time_tables`; records→exam/class; slot→record; timetable entry→record/slot/subject. Validate same-school edges and schedule conflicts. |

The tenant migration `2026_09_27_000000_create_schools_and_assign_school_ownership.php` adds `school_id` to: `users`, `class_types`, `my_classes`, `sections`, `subjects`, `student_records`, `exams`, `marks`, `exam_records`, `grades`, `dorms`, `pins`, `books`, `book_requests`, `staff_records`, `payments`, `payment_records`, `receipts`, `time_table_records`, `time_slots`, `time_tables`, `promotions`, `settings`, and `skills`. It assigns existing rows to a migration-created “Default School” and clears school ownership for `super_admin` users. `academic_years`, `terms`, attendance tables and audit logs define tenant ownership in their own migrations. Role/permission catalogs and location catalogs are global.

## Migration-by-migration target mapping (all 35)

| Laravel migration | Created/changed schema | Supabase/PostgreSQL treatment / review |
|---|---|---|
| `2013_09_20_121733_create_blood_groups_table.php` | `blood_groups` | Shared reference catalog, seed controlled reference values. |
| `2013_09_22_124750_create_states_table.php` | `states` | Shared location catalog. |
| `2013_09_22_124806_create_lgas_table.php` | `lgas` | FK to states; preserve cascade semantics. |
| `2013_09_26_121148_create_nationalities_table.php` | `nationalities` | Shared location catalog. |
| `2014_10_12_000000_create_users_table.php` | `users` | Retain legacy PK; add Auth UUID link; migrate credentials separately; no password exposure through Data API. |
| `2014_10_12_100000_create_password_resets_table.php` | `password_resets` | Do not migrate reset tokens as active credentials; use Supabase Auth recovery. |
| `2018_09_20_100249_create_user_types_table.php` | `user_types` | Legacy role labels; reconcile against `roles` before retiring. |
| `2018_09_20_150906_create_class_types_table.php` | `class_types` | Tenant scope; class-type FK. |
| `2018_09_22_073005_create_my_classes_table.php` | `my_classes` | Tenant scope; class type; scoped unique class names. |
| `2018_09_22_073526_create_sections_table.php` | `sections` | Tenant scope; class/teacher consistency; scoped unique. |
| `2018_09_22_080555_create_settings_table.php` | `settings` | Tenant-owned config; decide constrained key/value schema and secret exclusion. |
| `2018_09_22_081302_create_subjects_table.php` | `subjects` | Tenant scope; class/teacher consistency, scoped unique. |
| `2018_09_22_151514_create_student_records_table.php` | `student_records` | Tenant scope; link to profile/user, class, section, guardian, academic year; maintain admission uniqueness per school. |
| `2018_09_26_124241_create_dorms_table.php` | `dorms` | Tenant scope; scoped name uniqueness; student references. |
| `2018_10_04_224910_create_exams_table.php` | `exams` | Tenant scope; academic year/term, class/section; scoped uniqueness. |
| `2018_10_06_224846_create_marks_table.php` | `marks` | Tenant scope; student/class/section/subject/exam/grade constraints. |
| `2018_10_06_224944_create_grades_table.php` | `grades` | Tenant scope; class type and scoring interval constraints. |
| `2018_10_06_225007_create_pins_table.php` | `pins` | Tenant scope; review string `used`/`times_used` integer defaults; store PIN securely hashed and rate limit verification. |
| `2018_10_18_205550_create_skills_table.php` | `skills` | Tenant scope; student/assessment/teacher access. |
| `2018_10_18_205842_create_exam_records_table.php` | `exam_records` | Tenant scope; exam/class/section/student and period FKs. |
| `2018_10_31_191358_create_books_table.php` | `books` | Tenant scope; class FK. |
| `2018_10_31_192540_create_book_requests_table.php` | `book_requests` | Tenant scope; `returned` string numeric default review; book/user FKs. |
| `2018_11_01_132115_create_staff_records_table.php` | `staff_records` | Tenant scope; profile FK and scoped staff code. |
| `2018_11_03_210758_create_payments_table.php` | `payments` | Tenant fee schedule; currency, decimals and period relationship review. |
| `2018_11_03_210817_create_payment_records_table.php` | `payment_records` | Tenant ledger; decimal precision, idempotency and receipt linkage review. |
| `2018_11_06_083707_create_receipts_table.php` | `receipts` | Tenant-owned records; FKs to payment record; PDF output moves to object storage or generated response. |
| `2018_11_27_180401_create_time_tables_table.php` | `time_table_records`, `time_slots`, `time_tables` | Tenant scope; same-school composite refs and schedule conflict constraints. |
| `2019_09_22_142514_create_fks.php` | FK constraints on legacy tables | Translate all FK intent; ensure referenced and referencing types align; original `down()` empty. |
| `2019_09_26_132227_create_promotions_table.php` | `promotions` | Tenant scope added later; from/to classes, sections, student, academic-year constraints. |
| `2026_09_27_000000_create_schools_and_assign_school_ownership.php` | `schools`, 24 `school_id` columns/FKs; unique indexes replaced | Rebuild as target schema/legacy data backfill; don't run its Laravel default-school data action blindly in new Supabase design. Preserve super-admin `school_id NULL` intent in platform membership model. |
| `2026_09_27_000001_create_academic_periods_and_attendance_tables.php` | `academic_years`, `terms`, `attendance_sessions`, `attendance_records` | Preserve composite uniqueness/FKs and cascade behavior; review nullable SET NULL with composite references. |
| `2026_09_27_000002_create_audit_logs_table.php` | `audit_logs` | JSON properties; tenant/platform rows; append-only policies and index strategy. |
| `2026_09_27_000003_create_roles_and_permissions.php` | `roles`, `permissions`, `role_permissions`, `user_permissions`; inserts roles/grants/user_types | Convert seed inserts into reviewed reference data migration. Scope per-user overrides to membership. Do not automatically apply platform subscription permission as an implemented feature. |
| `2026_09_27_000004_link_legacy_records_to_academic_periods.php` | `academic_year_id`, `term_id`, promotion period IDs and data backfill | Rebuild data mapping in a staged migration script; verify imported legacy year/term values and row counts before constraints. |
| `2026_09_28_000000_add_slug_to_schools_table.php` | `schools.slug`, unique index/backfill | Preserve unique slug; ensure slug collision handling and school-host resolver mapping. |

## PostgreSQL compatibility findings and schema rules

- The previous static audit found no explicit MySQL-only or SQLite-only raw DDL, but PostgreSQL execution was not tested.
- `2018_10_06_225007_create_pins_table.php` (`used`, `times_used`) and `2018_10_31_192540_create_book_requests_table.php` (`returned`) use `string(...)->default(0)`, a likely PostgreSQL varchar-default type mismatch. Correct only in a future forward-safe conversion plan; do not edit old migration history now.
- `config/database.php` contains PostgreSQL configuration but defaults to MySQL and hard-codes `sslmode=prefer`. Supabase SSL must be explicit in future runtime setup.
- `2019_09_22_142514_create_fks.php` adds legacy FKs only after tables and has no rollback implementation. Data import must check orphan records before adding constraints.
- `unsignedInteger` has no native unsigned range in PostgreSQL. Preserve appropriate integer types/checks and ensure FKs have matching types.
- `school_id` is nullable in legacy `users` and many tables during migration. New design should make school ownership required for school-owned rows, except a deliberately modeled platform identity or truly shared row. Backfill/clean data before `NOT NULL`.
- Preserve `DECIMAL` money precision and set explicit currency semantics (existing schools have currency code); no floating point for balances.
- Keep audit JSON as `jsonb` after verifying JSON queries; index only observed query paths.
- Translate all uniqueness, composite indexes, FK actions (`cascade`, `set null`, `restrict`), timestamps/date types, and lookup indexes with names under PostgreSQL limits. Verify unique phone/email policy and nullable unique semantics intentionally.
- Add RLS to every exposed table and storage object. Supabase Data API grants and RLS are separate controls; keep table grants minimal.

## Migration execution sequence (future phase)

1. Export schema/data counts from a protected source without including them in Git; classify identities, school IDs, nullable/orphan FKs, duplicates and uploaded object paths.
2. Create new numbered SQL migrations under a Supabase-managed migration workflow. Build foundational schools/profiles/memberships/roles/reference tables first; then domain tables/FKs/indexes; then RLS policies; then safe seed/reference data.
3. Import stable school IDs, users/profile IDs and all domain rows preserving FK mapping. Import files separately after Storage policies are tested.
4. Provision Auth identities with verified account mapping; force recovery/reset when password hash format cannot be imported safely. Never copy Laravel reset tokens.
5. Compare source/target row counts, representative relationships, sums/payment balances, audit events and per-school exports before cutover.
6. Keep Laravel DB disconnected from production; perform cutover only with backup, rollback plan, freeze/dual-write decision and explicit approval.
