# Supabase migration readiness

## Status summary

| Area | Status | Evidence / next step |
|---|---|---|
| Source table inventory | Verified statically | 35 migration files create 40 application tables; see schema inventory. |
| PostgreSQL shape | Potentially compatible; untested | Laravel schema-builder DDL is largely portable. Fix string defaults, identity behavior, helper URL default and unsigned semantics. No PG was available/used. |
| Supabase SQL draft | Proposed only | `supabase/migrations/20261104000000_nexasms_existing_schema_proposal.sql`; not applied, not parsed/executed. |
| Tenant integrity | Requires target constraints and RLS | Laravel scopes are not a substitute for RLS and allow no-context queries. Add composite same-school relationships and deny-by-default policies. |
| Supabase Auth | Requires identity cutover | Add UUID bridge; account creation/invitation via trusted Edge Function; password reset handled by Supabase Auth. Do not assume Laravel bcrypt hashes are importable. |
| Storage | Requirements identified; configuration pending | Inventory legacy upload paths and classify each bucket public/private before copying files or authorizing access. |
| Data migration | Not ready | No database was accessed. Need approved source export, ID/sequence map, school backfill reconciliation and data validation plan. |
| Production cutover | Not ready | No credentials used; no Supabase connection, migration, or deployment performed. |

## Required implementation order

1. Freeze an authoritative schema/data snapshot from the existing application in a separately approved, protected process. Never take production credentials through this chat. Confirm whether the destination is empty; do not overwrite an existing Supabase schema.
2. Review the proposed SQL with a PostgreSQL parser and apply to a **disposable Supabase project** or approved non-production PostgreSQL environment. This was intentionally not done.
3. Fix/confirm source-model constraints: varchar zero defaults, photo fallback, optional unique values, identity reseeding, and same-school composite FKs. Validate all migration-created columns against application fillables/casts.
4. Build account mapping from legacy integer user IDs to Supabase Auth UUIDs. Decide password reset/first-login behavior; do not import bcrypt until compatibility is proven. Provision a school and first admin through an idempotent trusted workflow.
5. Implement and test RLS for every exposed table, helper functions, grants, and views. Use two isolated schools and every actual role. Verify reads and writes, including forged IDs, before allowing Vue to use the anon key.
6. Implement trusted server operations for school provisioning, attendance batches, payment/receipt transaction, audit append, promotions and PIN-gated student results. Edge Functions should verify caller JWT, then invoke narrow SQL transactions. Never expose `service_role`.
7. Configure Storage buckets/policies and migrate object files with path/record reconciliation. Verify signed URLs and cross-tenant denials.
8. Import lookup/catalog data and school/academic/user/core records in dependency order with explicit integer IDs; validate counts, orphans, totals and constraints; reseed identities.
9. Convert the Vue frontend and replace Laravel controllers one workflow at a time; retain feature parity and run role/tenant regression tests.
10. Perform a staged cutover, monitor logs, and keep rollback/export plans. Only then consider production migration approval.

## Dependency order for schema and import

Create global lookups (`blood_groups`, `states`, `nationalities`, `user_types`), then `schools`, roles/permissions catalog, `users` (defer `auth_user_id` mapping), class types/classes, sections, settings/subjects, dorms, academic years/terms, student/staff records, exams/grades, books, payments, timetables, marks/exam records, attendance, pins/requests/receipts/promotions, audit logs. Add all cross references only once both endpoints exist. `lgas` depends on `states`; user profile FK lookups depend on their catalogs; many legacy records depend on users/classes/sections. Actual import order must follow the complete FK graph and can use a staged constraint strategy only after PostgreSQL validation.

## Migration review details

- `2026_09_27_000000_create_schools_and_assign_school_ownership.php` inserts a `DEFAULT` school and backfills all legacy ownership. The proposed clean schema omits that data row; for a real data import, ownership mapping must be decided from actual source school records and must not infer all data belongs to one tenant.
- The same migration sets platform super-admin `school_id` to NULL and replaces several global unique constraints with school-scoped ones. Preserve this behavior. Existing records can reveal duplicate values once scoped; check before constraints.
- `2026_09_27_000004_link_legacy_records_to_academic_periods.php` synthesizes academic years and terms from old text/year fields. Treat it as a data conversion step for import; do not run its behavior against empty seedless schema.
- `2026_09_28_000000_add_slug_to_schools_table.php` derives unique slugs and updates records. Need collision mapping if a frontend URL already exists.
- `2019_09_22_142514_create_fks.php` has an empty rollback; migration history is not safely reversible by Laravel rollback. Supabase migration process needs forward-only review and backup.
- Role/permission migration inserts the app's fixed permission catalog and role grants. Those rows are required reference configuration, not demo school data. Preserve exact permissions and check role IDs by name, never assume numeric seed IDs.

## Existing feature coverage checklist

This is the Laravel source feature inventory relevant to the migration. Database tables alone do not preserve controller behavior; each item needs an equivalent Vue/API workflow before cutover.

| Existing feature/workflow | Source-backed records / logic | Target status |
|---|---|---|
| Login, logout, remember session, email verification, password reset | `users`, `password_resets`, `Auth::routes`, custom login/reset notifications | Supabase Auth + UUID bridge; reset out of legacy table; session behavior rewritten |
| Public registration and school onboarding | auth registration route and user/school management controllers | Trusted Edge provisioning; current registration path needs review because required school/user fields are not clearly supplied |
| Platform owner school/user management | `schools`, `users`, platform permissions, tenant middleware | privileged Edge/RPC operations with `platform.*` permissions; super-admin profile remains school NULL |
| School dashboards, school settings and tenant host resolution | `settings`, schools, `InitializeTenantContext`, `SchoolHostResolver` | frontend host resolver + verified school membership; host/slug alone is not authorization |
| Roles and per-user overrides | `user_types`, `roles`, `permissions`, `role_permissions`, `user_permissions`, `PermissionService` | preserve exact catalog/override behavior; no browser grant mutations |
| Students and guardians; student/parent portals | `users`, `student_records`, `student_records.my_parent_id` | relationship-scoped policies/RPC; parent sees linked child only |
| Teachers/staff and assignments | `users`, `staff_records`, `sections.teacher_id`, `subjects.teacher_id` | teacher assignments enforced server-side and in RLS |
| Classes, sections, subjects, grades, dormitories | `class_types`, `my_classes`, `sections`, `subjects`, `grades`, `dorms` | same-school relationships; permission-gated CRUD |
| Academic years and terms | `academic_years`, `terms`; legacy `year`/`term` strings | preserve legacy strings plus normalized references; import transform required |
| Attendance marking, reports, student history | `attendance_sessions`, `attendance_records`; controller validates exact roster | transactional attendance operation and relationship-scoped history |
| Exams, marks, tabulation, print reports, student result view | `exams`, `marks`, `exam_records`, `grades`, `pins`, `skills` | permission/tenant enforcement; PIN/session result flow must be rewritten safely |
| Fees, payment recording, balance and receipts | `payments`, `payment_records`, `receipts`; `PaymentController@pay_now` updates and inserts receipt | atomic row-locked function; private receipt records/files; prevent overpayment |
| Library catalogue and book requests | `books`, `book_requests`, `library.manage` | own-school policies and borrower relationship rules |
| Timetables and time slots | `time_table_records`, `time_slots`, `time_tables` | own-school constraints and assigned teacher reads |
| Student promotion, repeat, graduation | `promotions`, student class/section/session/grad fields | transactional server operation; preserve P/D/G and resulting linked fields |
| Reports and exports | controllers/services query students, attendance, marks, payments | reproduce each report with permission-scoped database/API queries |
| Audit history | `audit_logs`, audit middleware/service | append-only server-side writes; school/platform permission-based reads |
| Profile photos, school logo, documents and receipt assets | `users.photo`, `schools.logo`, legacy storage paths, book URLs/locations | Storage plan and file inventory required; private educational/financial objects must not become public |
| Geography/profile lookup AJAX | `states`, `lgas`, `blood_groups`, `nationalities`, route lookups | read-only catalog API; confirm whether public registration needs anonymous lookup access |
| Legal pages | Blade terms/privacy routes | static Vue pages or public content route; not a database migration concern |
| API identity endpoint | `routes/api.php` `/api/user` | replace with Auth identity/profile endpoint returning allowlisted fields |

Inventory scope was checked against `routes/web.php`, `routes/api.php`, route-bound controllers, the 35 migration files, model relationship declarations, and permission middleware. Some report/export behavior is distributed in views/services and still requires page-by-page frontend parity testing; this is not proof of feature completion.

## Security gates before any apply

- Confirm all 40 tables in the SQL draft correspond one-to-one to Laravel tables.
- Confirm no unexpected existing objects would be replaced; the proposed SQL uses create statements and should fail rather than overwrite.
- Ensure `auth.uid()` lookup, permission helper, and RLS policy SQL compile. Review definer grants and fixed `search_path` manually.
- Verify RLS enabled and forced on every API-exposed application table, plus explicit table grants. RLS does not replace grants and does not affect privileged service-role access.
- Confirm private schema is not exposed through Supabase API; no function accidentally bypasses RLS; avoid broad service-role Edge Functions.
- Execute the RLS test matrix in `supabase-rls-design.md` for anonymous, unassigned, each school role and foreign-school access, with both positive and negative cases.

## Scope / execution record

No file outside the four requested report paths and one new SQL draft should change. This task did not connect to `vyiyveqnsjyxrekbwdeg`, run Supabase CLI, Docker, local PostgreSQL, migrations, seeders, or any database command. There are no runtime migration results to report. The SQL is a review artifact, not production-ready proof.
