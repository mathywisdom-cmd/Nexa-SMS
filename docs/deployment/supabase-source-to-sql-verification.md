# Source-to-Supabase SQL verification

**Status:** static review only. Target stated by requester: Supabase PostgreSQL 17. No database, Supabase project, CLI, Docker, or PostgreSQL parser was used. No SQL was applied. The proposed migration remains a fail-closed draft, not a deployable application schema.

**Follow-up:** `supabase-final-preflight-review.md` supersedes this report's earlier MATCH FULL/nullability assessment and obsolete-fillable classification. This report now includes the complete original-FK inventory. The current proposal retains original scalar constraints alongside nullable-school composite constraints, uses MATCH SIMPLE where nullable `school_id` requires it, and keeps MATCH FULL for `attendance_records`, whose `school_id` is required. PostgreSQL execution remains untested.

## Scope inspected

- All **35** files under `database/migrations/`, including later schema modifications, data backfills, slug generation, and the deferred legacy-FK migration.
- All model files under `app/Models/`, the root `app/User.php`, model traits for tenant/academic-year synchronization, `app/Services/PermissionService.php`, tenant middleware/resolver, and relevant student, mark/PIN, attendance, payment, promotion, school/user, and role workflows.
- The current proposed SQL: `supabase/migrations/20261104000000_nexasms_existing_schema_proposal.sql`.
- Existing planning reports in `docs/deployment/` were consulted for feature context; this verification changes only the SQL draft and creates this report.

Laravel migrations create **40 application tables** (plus Laravel's migration bookkeeping table). The SQL has 40 `CREATE TABLE public.*` statements and enables/forces RLS on 40 tables. Table and column definitions were compared against the final migration chain, not only the initial creates.

## Table-by-table result

`VERIFIED` means the table's final migration columns and source constraints were matched, subject to specifically documented target additions. `CORRECTED` means the draft had a source-backed discrepancy and has been edited. `UNRESOLVED` means source code disagrees with the migration schema or a compatibility decision cannot be established from source alone.

| Laravel table | Result | Verification notes |
|---|---|---|
| `blood_groups` | VERIFIED | Integer identity, name, nullable Laravel timestamps; lookup table. |
| `states` | VERIFIED | Integer identity, name, timestamps. |
| `lgas` | VERIFIED | `state_id` integer FK to states, delete cascade. |
| `nationalities` | VERIFIED | Integer identity, name, timestamps. |
| `users` | CORRECTED | Required `code` restored; photo is required with source helper default represented by a relative default; `school_id` remains nullable for platform owner; UUID `auth_user_id` is an explicit Supabase addition. |
| `password_resets` | VERIFIED | Email index, token, nullable created timestamp; no primary key in source. It must not be used after Supabase Auth cutover. |
| `user_types` | VERIFIED | `title`, `name`, `level` are strings, not numeric types; exact five conditional role-type inserts are included. |
| `class_types` | CORRECTED | Tenant `school_id` nullability now matches migration 30; school delete is RESTRICT. |
| `my_classes` | CORRECTED | Tenant `school_id` nullable; unique is school/class-type/name; optional class type delete sets only `class_type_id` NULL in the composite target FK. |
| `sections` | CORRECTED | Tenant `school_id` nullable; active is signed tiny integer represented as bounded smallint; class deletion cascades, teacher deletion nulls only teacher ID. |
| `settings` | CORRECTED | Tenant `school_id` nullable and school FK RESTRICT. |
| `subjects` | CORRECTED | Tenant `school_id` nullable; class/name unique is school scoped; class delete cascades and teacher FK has default NO ACTION. |
| `student_records` | CORRECTED | Tenant `school_id` nullable; original fields and nullable academic-year addition represented; target adds same-school FKs. Model fillable fields `wd` and `wd_date` do not exist in any migration (see unresolved list). |
| `dorms` | CORRECTED | Tenant `school_id` nullable; unique name is replaced with school/name; school delete RESTRICT. |
| `exams` | CORRECTED | Tenant `school_id` nullable; unique school/term/year; target academic-year/term links add composite same-school constraints beyond the source migration. |
| `marks` | CORRECTED | Tenant `school_id` nullable; source score/comment fields and nullable academic-period columns represented; target relationships are composite by school. |
| `grades` | CORRECTED | Tenant `school_id` nullable; existing unique(name,class_type_id,remark) preserved; source class-type FK is **ON DELETE CASCADE** (corrected from SET NULL). |
| `pins` | CORRECTED | `used` and `times_used` remain varchar with PostgreSQL-compatible string default `'0'`; nullable user/student IDs; direct API access remains denied. |
| `skills` | VERIFIED | `class_type` is nullable varchar, not an FK; tenant `school_id` nullable. |
| `exam_records` | UNRESOLVED | Migration fields represented, including required `year`; model `$fillable` additionally lists `af_id` and `ps_id`, absent from all migrations. |
| `books` | CORRECTED | Tenant `school_id` nullable; optional class FK/cascade and original nullable book metadata represented. |
| `book_requests` | CORRECTED | `returned` remains varchar with default `'0'`; tenant `school_id` nullable; book/user FKs cascade. |
| `staff_records` | CORRECTED | Tenant `school_id` nullable; nullable code and school-scoped unique; user FK cascades. |
| `payments` | CORRECTED | Tenant `school_id` nullable; method default `cash`; school-scoped reference unique; class FK cascade; year and academic fields retained. |
| `payment_records` | CORRECTED | Tenant `school_id` nullable; paid remains signed tiny integer/default 0; school-scoped optional reference unique and payment/student relationships retained. |
| `receipts` | CORRECTED | Tenant `school_id` nullable; integer amounts and payment-record FK cascade retained. |
| `time_table_records` | CORRECTED | Tenant `school_id` nullable; both school-scoped unique constraints represented; nullable exam FK cascades. |
| `time_slots` | CORRECTED | Tenant `school_id` nullable; original string time fields and unique(timestamp_from,timestamp_to,ttr_id) retained. |
| `time_tables` | CORRECTED | Tenant `school_id` nullable; both unique constraints and nullable subject/exam date/day retained. |
| `promotions` | CORRECTED | Tenant `school_id` nullable; original student/class/section cascade FKs, status/session fields and later year ID columns represented. |
| `schools` | VERIFIED | Original columns/defaults, unique code and nullable unique slug represented; no default school row inserted. |
| `academic_years` | VERIFIED | Required school ID, dates, boolean default, unique(school,name) and (school,id); school cascade. |
| `terms` | VERIFIED | Required school/year, date columns, unique and composite school/year FK; school cascade. |
| `attendance_sessions` | VERIFIED | Explicit named unique and indexes, class/section cascades, academic references and marker null behavior represented; target composite tenant FKs are additional hardening. |
| `attendance_records` | VERIFIED | Named unique and source columns/FKs represented; target composite tenant FKs are additional hardening. |
| `audit_logs` | VERIFIED | Big integer identity, nullable school/actor, JSONB adaptation, indexes and SET NULL FKs represented. |
| `roles` | VERIFIED | Exact role names, labels and scope values from migration seed. |
| `permissions` | VERIFIED | Source `description` is **NOT NULL**; SQL is correct. The earlier schema inventory's nullable mark was wrong and is called out below; SQL was not changed for this item. |
| `role_permissions` | VERIFIED | Composite primary key and both cascade FKs; exact role grants seeded by role/permission names. |
| `user_permissions` | VERIFIED | Unique user/permission override, allowed default true, grantor nullable and SET NULL; writes remain denied to client roles. |

## Source-backed corrections made to the SQL

1. Changed all 24 legacy school-owned `school_id` columns back to nullable. Migration `2026_09_27_000000_create_schools_and_assign_school_ownership.php` adds each as nullable and never alters it to NOT NULL. It backfills existing rows and sets super-admin `users.school_id` to NULL, but that does not make the DB column non-nullable. RLS must deny null-school data rows. This is a correction to the previous draft's unsupported NOT NULL tightening.
2. Restored `users.code` as NOT NULL. The original users migration declares it required; the later tenant migration only replaces its unique key.
3. Restored `users.photo` as NOT NULL and used a relative `global_assets/images/user.png` default instead of an environment-built absolute URL. `Qs::getDefaultUserImage()` returns `asset('global_assets/images/user.png')`; this is a target portability adaptation, and existing stored photo URLs need an explicit path conversion during data import.
4. Corrected `grades.class_type_id` delete behavior to CASCADE, matching `2019_09_22_142514_create_fks.php`.
5. Changed Laravel `timestamp`/`timestamps()` columns from `timestamptz` to PostgreSQL `timestamp without time zone`. Laravel 8.42.1's `PostgresGrammar::typeTimestamp()` emits that exact type. The earlier inventory's generic timestamptz description is inaccurate; timestamp-with-time-zone would be a semantic change.
6. Corrected `sections`' school-scoped unique index column order to `(school_id,my_class_id,name)`, matching migration 30. The order affects the index access path even though the uniqueness set is equivalent.
7. Added the original nullable school ID indexes and academic-period indexes that the SQL draft had omitted. Composite `(school_id,id)` unique keys support the additional same-school foreign keys.
8. Added signed tiny-integer range checks to the PostgreSQL `smallint` representations of Laravel `tinyInteger` fields, preserving the -128..127 range.
9. Updated authorization helper permission filtering to mirror `PermissionService`: platform-role status must match the `platform.` permission prefix for both role grants and per-user overrides. Added authenticated-only schema usage/function execution grants needed for future RLS policy calls; public and anon execution remain revoked. The schema is still not API-exposed by this draft and has no allow policies.
10. Changed the student composite FKs on the five nullable-school tables to default `MATCH SIMPLE` and restored their source scalar user FKs. Migration 30 backfills rows that exist at migration time, but the existing post-migration `DatabaseSeeder` creates NULL-school users and student profiles. Non-NULL school values still activate same-school/profile checks; NULL-school rows retain the legacy user-existence FK. `attendance_records.school_id` is non-null by migration 31, so its MATCH FULL FK remains. PIN nullable-student behavior was also checked and retained.
11. Restored the remaining 35 original scalar relationships on nullable-school legacy tables alongside their same-school composites. This preserves every original scalar reference and delete action when the composite FK is skipped for a NULL school.

No SQL correction was needed for `permissions.description`: migration 33 declares `$table->string('description')` without `nullable()`. The older schema inventory incorrectly marked it nullable. The SQL correctly uses NOT NULL. The preexisting inventory report was left untouched under this task's scope.

## Keys, indexes and relationship findings

- All 40 tables retain integer identity primary keys, except `audit_logs.id`, which is bigint. `GENERATED BY DEFAULT AS IDENTITY` permits explicit legacy IDs during import; sequence reseeding remains required after import.
- The draft includes all source unique keys, including migration 30's school-scoped replacements, school slug uniqueness, academic/attendance keys, and the role catalog constraints. PostgreSQL's ordinary UNIQUE behavior allows multiple NULL values, matching the legacy MySQL intent for optional unique values. Validate any application assumption about duplicate nulls before import.
- The 24 legacy school ownership columns have per-table indexes, matching migration 30. Academic-year/term and promotion year indexes from migration 34 are represented. Explicit attendance and audit indexes are preserved.
- Existing FKs and delete actions were checked against `2019_09_22_142514_create_fks.php`, migration 30, academic/attendance migration 31, audit migration 32, and roles migration 33. Unspecified `ON UPDATE` behavior is PostgreSQL NO ACTION, equivalent to the Laravel/MySQL default.
- Laravel 8.42.1's PostgreSQL grammar maps `$table->timestamp()` and `$table->timestamps()` to `timestamp without time zone`; SQL uses that exact form. Laravel `json` is adapted to PostgreSQL `jsonb` in the draft, which is compatible for JSON values but can differ in object key ordering/duplicate-key handling and should be considered when importing/querying audit `properties`.
- The target adds composite FKs for same-school references and `(school_id,id)` candidate keys. Five student-related composites (`student_records.user_id`, `marks.student_id`, `exam_records.student_id`, `payment_records.student_id`, `promotions.student_id`) use MATCH SIMPLE because their legacy `school_id` is nullable and the existing post-migration `DatabaseSeeder` creates schoolless sample users/profiles. Their source scalar user FKs are retained. With a non-NULL school, the composite enforces same-school profile linkage; with NULL school, it is skipped and only the original user-existence FK remains. `attendance_records.school_id` is non-null in migration 31, so its MATCH FULL composite remains. RLS must reject NULL-school rows to API users; imported/seeded NULL rows require separate checks if retained.
- Composite `SET NULL (column)` clauses null only the nullable relationship column rather than the school ID. PostgreSQL 17 supports the column-targeted form. This was not parsed or executed here.
- A notable original constraint remains global: `pins.code` is unique globally. Do not silently change to school-scoped uniqueness without a data/business-rule review.

## Known source/code discrepancies and unresolved questions

1. **Model fields absent from migrations:** `StudentRecord::$fillable` includes `wd` and `wd_date`; `ExamRecord::$fillable` includes `af_id` and `ps_id`. The follow-up preflight found them only in `$fillable`, with no controller/view/report usage, so classifies them as obsolete in current repository source. External live-database columns remain unverified. The SQL does not invent them.
2. **Legacy school ownership nullability:** source allows NULL in all 24 columns. Migration 30 backfills rows that exist at that time, but `DatabaseSeeder` runs later and creates NULL-school sample rows because it has no tenant context. The target preserves nullable columns. Future client RLS must reject ownerless records; imported ownerless data needs explicit review and may not satisfy same-school composites when those use MATCH SIMPLE.
3. **`users.photo` default:** source uses an absolute URL generated from runtime `APP_URL`. Relative storage keys are more portable, but the frontend/storage conversion behavior must be agreed and data import must normalize existing values.
4. **Period FKs:** migration 34 adds indexed nullable `academic_year_id`, `term_id`, `from_academic_year_id`, and `to_academic_year_id` columns and performs legacy string-to-period data backfills; it does not add FKs for these legacy columns. The SQL adds composite same-school FKs. These are deliberate target integrity additions; validate legacy orphan/mismatched data before import.
5. **Role catalog seeds:** migration 33 inserts fixed roles, permissions, exact grants, and five `user_types` rows. The proposed SQL includes those exact reference rows. It does not include any school, user, or demo data. Per-user `user_permissions` are actual deployment data and cannot be reconstructed without a data export.
6. **School slug generation:** migration 35 keeps a valid existing slug; otherwise it applies `Str::slug(name)` or `school`, then appends `-2`, `-3`, etc. while avoiding both reserved valid slugs and already-used slugs, in ascending school ID order. SQL enforces uniqueness but does not generate slugs; the future provisioning API must implement the same behavior.
7. **Target DDL behavior:** all 24 legacy school FKs use RESTRICT as migration 30 specifies. Academic-year and attendance tables retain their own source CASCADE behavior. Composite relationship FKs add tenant consistency but do not alter RLS requirements.
8. **Auth identity:** `auth_user_id` is an added nullable unique FK to `auth.users(id)`. No existing Laravel migration supplies it. Profile mapping, account creation, invitations, and password recovery require a future trusted Auth/Edge workflow. Laravel bcrypt hashes must not be assumed importable.
9. **Helper execution context:** the private helpers are SECURITY DEFINER with an empty search path and `row_security=off`, and are granted only to `authenticated`. This supports policy calls only if the function owner has appropriate table access/BYPASSRLS. Confirm function ownership and test recursive-RLS behavior on a disposable Supabase project. Keep `private` out of exposed API schemas.
10. **Legacy relations remain scalar in Laravel:** same-school constraints are newly added. Verify the old data has no cross-school links before imposing them.

## Application workflows not preserved by schema alone

- Student and parent visibility depends on `student_records.user_id` and `my_parent_id`; parents must see only linked children.
- Teacher access depends on both `sections.teacher_id` and `subjects.teacher_id`, with section/class/subject checks. A school-wide teacher policy would be too broad.
- Student marks display can require PIN verification. Laravel stores `pin_verified` in the session; a Supabase RLS policy cannot read that Laravel session. Keep direct student/parent reads of `marks`, `exam_records`, and `pins` denied until a protected RPC/Edge result flow preserves the PIN rules.
- Attendance store validates that submitted student IDs exactly equal the current section roster inside a transaction. A future server operation must reject incomplete/foreign roster batches atomically.
- Payment recording updates paid amount, balance and completion state, creates a receipt and writes audit data. Future API must check positive amount, balance ceiling, tenant and record ownership, and commit atomically; row locking is needed for concurrency.
- Promotion changes student class/section/session/graduation state and records a promotion. Reproduce existing repeat/promote/graduate behavior transactionally before cutover.
- Authentication, school registration, first-admin creation, reset email, school-host routing, uploads/storage access, reports, exports, PDF/receipt rendering, and dashboard aggregates all need API/frontend equivalents. No Edge Function or Vue implementation is added here.

## Security review

- RLS is enabled and forced on all 40 tables. There are no permissive policies and no table grants to `anon` or `authenticated`; the draft intentionally denies all table API access.
- `private.current_profile_id`, `private.current_school_id`, and `private.has_permission` resolve through `auth.uid()` and the `users.auth_user_id` link, not user metadata. `has_permission` mirrors per-user override precedence and the platform-prefix guard.
- The private helper schema is not granted to anon and is not configured as a PostgREST exposed schema here. Authenticated has schema USAGE and execute only on the three helpers so future RLS policies can call them. Review owner privileges and function exposure before applying.
- Role and membership tables remain inaccessible to browser writes. The SQL does not yet provide a secure provisioning/RPC path; role elevation prevention currently follows from no grants/policies, not a fully implemented policy set.
- Storage objects are not part of these 40 tables. No bucket or storage policy is included. Student documents, receipts, and personal records require private buckets and school/relationship authorization; logos/photos need an explicit public/private classification.

## Final readiness assessment

**Source shape: mostly VERIFIED, with the explicit corrections and remaining questions above. SQL execution: NOT TESTED. Security policy behavior: NOT TESTED and not yet implemented for application access.** The final preflight considers the proposal ready for isolated PostgreSQL 17 DDL/syntax testing only, not as a verified migration or production schema. Next, run the entire migration in a disposable PostgreSQL 17/Supabase test project, inspect actual NULL-school imported/seed rows and composite FK behavior, then run constraint/application and role-by-role RLS tests. No production connection or migration should occur from this report.

## Complete original foreign-key inventory

The 47 legacy scalar FKs below come from `2019_09_22_142514_create_fks.php` and `2019_09_26_132227_create_promotions_table.php`. Each field mapping is enumerated; `CASCADE`, `SET NULL`, or `NO ACTION` is the original delete behavior (Laravel's omitted action is `NO ACTION`). For nullable-school legacy tables, the scalar is retained and the same-school composite is retained alongside it. This allows the scalar to validate references when `school_id` is NULL, while the composite additionally checks same-school ownership when it is populated.

| Source table | Every original scalar FK: field → target (delete action) | SQL equivalent and final status |
|---|---|---|
| `lgas` | `state_id → states.id` (CASCADE) | Scalar retained unchanged; `lgas_state_id_foreign`. |
| `users` | `state_id → states.id` (SET NULL); `lga_id → lgas.id` (SET NULL); `bg_id → blood_groups.id` (SET NULL); `nal_id → nationalities.id` (SET NULL) | All four scalar FKs retained unchanged. The new tenant `school_id` FK is separate. |
| `my_classes` | `class_type_id → class_types.id` (SET NULL) | Scalar `my_classes_class_type_legacy_fk` retained alongside the same-school composite. |
| `sections` | `my_class_id → my_classes.id` (CASCADE); `teacher_id → users.id` (SET NULL) | Scalars `sections_my_class_legacy_fk` and `sections_teacher_legacy_fk` retained alongside composites. Both reference the original target tables and preserve delete actions. |
| `subjects` | `teacher_id → users.id` (NO ACTION); `my_class_id → my_classes.id` (CASCADE) | Scalars `subjects_teacher_legacy_fk` and `subjects_my_class_legacy_fk` retained alongside composites. |
| `student_records` | `user_id → users.id` (CASCADE); `my_class_id → my_classes.id` (CASCADE); `section_id → sections.id` (CASCADE); `my_parent_id → users.id` (SET NULL); `dorm_id → dorms.id` (SET NULL) | All five scalar FKs retained alongside composites. `student_records_user_id_legacy_fk`, `student_records_my_class_legacy_fk`, `student_records_section_legacy_fk`, `student_records_parent_legacy_fk`, `student_records_dorm_legacy_fk`. |
| `marks` | `student_id → users.id` (CASCADE); `my_class_id → my_classes.id` (CASCADE); `section_id → sections.id` (CASCADE); `subject_id → subjects.id` (CASCADE); `exam_id → exams.id` (CASCADE); `grade_id → grades.id` (SET NULL) | All six original scalars retained alongside the stricter student-profile/same-school constraints. Named `marks_*_legacy_fk`. |
| `grades` | `class_type_id → class_types.id` (CASCADE) | Scalar `grades_class_type_legacy_fk` retained alongside composite. |
| `pins` | `user_id → users.id` (CASCADE); `student_id → users.id` (CASCADE) | Both original scalar FKs retained as `pins_user_id_legacy_fk` and `pins_student_user_legacy_fk`, alongside same-school/profile composites. Neither scalar was redirected to the wrong table. |
| `exam_records` | `exam_id → exams.id` (CASCADE); `my_class_id → my_classes.id` (CASCADE); `student_id → users.id` (CASCADE); `section_id → sections.id` (CASCADE) | All four scalars retained alongside composites; named `exam_records_*_legacy_fk`. |
| `books` | `my_class_id → my_classes.id` (CASCADE; nullable column) | Scalar `books_my_class_legacy_fk` retained alongside composite. |
| `book_requests` | `book_id → books.id` (CASCADE); `user_id → users.id` (CASCADE) | Both scalars retained alongside composites as `book_requests_book_legacy_fk` and `book_requests_user_legacy_fk`. |
| `staff_records` | `user_id → users.id` (CASCADE) | Scalar `staff_records_user_legacy_fk` retained alongside composite. |
| `payments` | `my_class_id → my_classes.id` (CASCADE; nullable column) | Scalar `payments_my_class_legacy_fk` retained alongside composite. |
| `payment_records` | `payment_id → payments.id` (CASCADE); `student_id → users.id` (CASCADE) | Both scalars retained alongside composites as `payment_records_payment_legacy_fk` and `payment_records_student_user_legacy_fk`. |
| `receipts` | `pr_id → payment_records.id` (CASCADE) | Scalar `receipts_payment_record_legacy_fk` retained alongside composite. |
| `time_table_records` | `exam_id → exams.id` (CASCADE; nullable column); `my_class_id → my_classes.id` (CASCADE) | Both scalars retained alongside composites as `time_table_records_exam_legacy_fk` and `time_table_records_my_class_legacy_fk`. |
| `time_slots` | `ttr_id → time_table_records.id` (CASCADE) | Scalar `time_slots_ttr_legacy_fk` retained alongside composite. |
| `time_tables` | `ttr_id → time_table_records.id` (CASCADE); `ts_id → time_slots.id` (CASCADE); `subject_id → subjects.id` (CASCADE; nullable column) | All three scalars retained alongside composites as `time_tables_*_legacy_fk`. |
| `promotions` | `student_id → users.id` (CASCADE); `from_class → my_classes.id` (CASCADE); `from_section → sections.id` (CASCADE); `to_section → sections.id` (CASCADE); `to_class → my_classes.id` (CASCADE) | All five scalars retained alongside composites as `promotions_*_legacy_fk`. |

The Eloquent cross-check covered `StudentRecord` (`user`, `my_parent`, `my_class`, `section`, `dorm`), `Section` (`my_class`, `teacher`), `Subject` (`my_class`, `teacher`), `Mark` (`exam`, `section`, `my_class`, `user` via `student_id`, `subject`, `grade`), `Payment`/`PaymentRecord` (`my_class`, `payment`, `student` via `student_id`, receipt relation), `Promotion` (student via `User`, source/target classes and sections, period relations), `Pin` (both nullable user/student relations via `User`), `AttendanceSession`/`AttendanceRecord` (class, section, year, term, marker, session, student via `User`), `Receipt`, `TimeTableRecord`, `TimeSlot`, and `TimeTable`. The SQL companion scalars point to those same original tables/IDs. Composite links to `student_records(school_id,user_id)` are additional target integrity for school-owned student workflows; the original scalar user FK is retained, and NULL-school legacy rows retain the migration's original FK behavior.

### Other original foreign keys from later Laravel migrations

| Source migration/table | Original FK(s) | SQL equivalent and final status |
|---|---|---|
| `2026_09_27_000000_create_schools_and_assign_school_ownership.php`: all 24 tenant tables (`users`, `class_types`, `my_classes`, `sections`, `subjects`, `student_records`, `exams`, `marks`, `exam_records`, `grades`, `dorms`, `pins`, `books`, `book_requests`, `staff_records`, `payments`, `payment_records`, `receipts`, `time_table_records`, `time_slots`, `time_tables`, `promotions`, `settings`, `skills`) | Each table's `school_id → schools.id` (RESTRICT) | All 24 scalar school FKs are still declared inline. This migration introduces their columns as nullable and backfills existing rows; the SQL does not make those columns NOT NULL. |
| `2026_09_27_000001_create_academic_periods_and_attendance_tables.php`: `academic_years` | `school_id → schools.id` (CASCADE) | Scalar retained inline; school_id is NOT NULL. |
| Same migration: `terms` | `school_id → schools.id` (CASCADE); composite `(school_id,academic_year_id) → academic_years(school_id,id)` (CASCADE) | Both original constraints retained; school_id and academic_year_id are NOT NULL. |
| Same migration: `attendance_sessions` | `school_id → schools.id` (CASCADE); `my_class_id → my_classes.id` (CASCADE); `section_id → sections.id` (CASCADE); `academic_year_id → academic_years.id` (SET NULL); `term_id → terms.id` (SET NULL); `marked_by → users.id` (SET NULL) | School FK retained scalar; remaining links are composite same-school equivalents. `school_id` is NOT NULL; nullable optional IDs retain NULL behavior. |
| Same migration: `attendance_records` | `school_id → schools.id` (CASCADE); `attendance_session_id → attendance_sessions.id` (CASCADE); `student_id → users.id` (CASCADE) | School FK and original student scalar retained. Session composite is equivalent because both local key columns are NOT NULL. The student also has a same-school student-profile composite; its MATCH FULL is compatible because school_id is NOT NULL. |
| `2026_09_27_000002_create_audit_logs_table.php`: `audit_logs` | `school_id → schools.id` (SET NULL); `actor_id → users.id` (SET NULL) | Both scalar FKs retained unchanged. Audit actor-school trigger adds a consistency check without replacing either FK. |
| `2026_09_27_000003_create_roles_and_permissions.php`: `role_permissions` | `role_id → roles.id` (CASCADE); `permission_id → permissions.id` (CASCADE) | Both scalar FKs retained unchanged. |
| Same migration: `user_permissions` | `user_id → users.id` (CASCADE); `permission_id → permissions.id` (CASCADE); `granted_by → users.id` (SET NULL) | All three scalar FKs retained unchanged. |
| `2026_09_27_000004_link_legacy_records_to_academic_periods.php` and `2026_09_28_000000_add_slug_to_schools_table.php` | No foreign keys added. | No source FK to restore. The SQL's academic-year/term composites are additional same-school constraints, not translations of Laravel FKs. |

### Redundancy and integrity notes

- The 42 named `*_legacy_fk` scalar constraints on nullable-school relationships are intentionally redundant when `school_id` is populated: their companion composite adds the school-equality condition. They are necessary when `school_id` is NULL because MATCH SIMPLE skips the composite; the scalar preserves the Laravel existence and delete behavior. One additional scalar user FK is retained for `attendance_records.student_id`; that table has non-null school ownership.
- Student scalar FKs point to the same target as the Laravel migrations (`users.id`). The additional composite FK to `student_records(school_id,user_id)` is an intentional target enhancement for school-owned records; it does not replace the original user FK.
- `attendance_records.student_id → users.id` is logically redundant when `school_id` is populated because the student-profile composite and `student_records.user_id → users.id` already establish the chain. It remains to preserve the explicit Laravel FK and its cascade semantics. It is not a wrong-table reference.
- Duplicate scalar/composite cascade or SET NULL paths have not been executed against PostgreSQL. Their migration-time behavior, including whether equivalent delete actions interact cleanly, must be checked in the isolated PostgreSQL 17 test. No PostgreSQL validation is claimed.
- The remaining composite-only relationships on nullable-school legacy tables skip when school_id is NULL. Where they correspond to a Laravel scalar FK, the table above now shows its scalar companion. New academic-period composites have no Laravel scalar predecessor and may skip for NULL-school rows; those fields were introduced nullable without source FKs, so this is an added target constraint rather than removed source integrity.

### Scalar-FK comparison pass

A final static comparison extracted all 47 scalar FK declarations from the two original relationship migrations and checked the proposed SQL for the same source column, referenced table, and delete action. All 47 are represented. For `subjects.teacher_id`, Laravel omitted `onDelete()`, which means the source behavior is `NO ACTION`; the SQL now spells out `ON DELETE NO ACTION` explicitly. The other four lookup relationships and `lgas.state_id` remain scalar constraints, and every nullable-school legacy FK has its scalar companion. This is a text/source comparison only: it does not parse or execute PostgreSQL SQL.

## Foreign-key interaction audit

### Scalar and composite pairs

All 43 scalar/composite companion pairs in the draft were checked for referenced key and referential actions. Every pair uses the same `ON DELETE` action, and neither source migrations nor the SQL specify `ON UPDATE`; both therefore retain the default `NO ACTION` update behavior. For the 42 nullable-school legacy relationships, the composite uses default `MATCH SIMPLE`, so it does not require a school ID when that value is NULL; the scalar still validates the original referenced row. With a populated school ID, the composite adds the same-school rule. `attendance_records.school_id` is non-null, so its student composite uses `MATCH FULL`; its original scalar student FK is also retained.

| Table | Original scalar / composite relationship(s) | Delete action(s) | Static interaction result |
|---|---|---|---|
| `my_classes` | `class_type_id`: `class_types.id` / `(school_id,id)` | SET NULL / SET NULL on `class_type_id` | Same outcome; ownership column is preserved. |
| `sections` | `my_class_id`: `my_classes.id` / `(school_id,id)`; `teacher_id`: `users.id` / `(school_id,id)` | CASCADE / CASCADE; SET NULL / SET NULL on `teacher_id` | Same outcomes; school ownership is preserved when teacher is cleared. |
| `subjects` | `my_class_id`: `my_classes.id` / `(school_id,id)`; `teacher_id`: `users.id` / `(school_id,id)` | CASCADE / CASCADE; NO ACTION / NO ACTION | Same outcomes; teacher deletion remains blocked while referenced. |
| `student_records` | `user_id`: `users.id` / `(school_id,id)`; `my_class_id`: `my_classes.id` / `(school_id,id)`; `section_id`: `sections.id` / `(school_id,id)`; `my_parent_id`: `users.id` / `(school_id,id)`; `dorm_id`: `dorms.id` / `(school_id,id)` | CASCADE / CASCADE for user, class, section; SET NULL / SET NULL for parent and dorm | Same outcomes; nullable links are cleared without clearing `school_id`. |
| `marks` | `student_id`: `users.id` scalar plus `student_records(school_id,user_id)` composite; `my_class_id`, `section_id`, `subject_id`, `exam_id`, `grade_id`: each original parent ID plus same-school parent key | CASCADE on student/class/section/subject/exam; SET NULL on grade | Same outcome per relation. Student deletion can reach marks directly and through student profile; both paths delete the same marks. |
| `grades` | `class_type_id`: `class_types.id` / `(school_id,id)` | CASCADE / CASCADE | Same outcome. |
| `pins` | `user_id`: `users.id` / `(school_id,id)`; `student_id`: `users.id` scalar plus `student_records(school_id,user_id)` composite | CASCADE / CASCADE | Same delete outcome. Deleting a user may reach a PIN through either direct link; both delete the PIN. |
| `exam_records` | `exam_id`, `my_class_id`, `section_id`: original parent ID plus same-school key; `student_id`: `users.id` scalar plus student profile composite | CASCADE on all | Same outcome per relation; user deletion may also cascade through the student profile. |
| `books` | `my_class_id`: `my_classes.id` / `(school_id,id)` | CASCADE / CASCADE | Same outcome; nullable class ID means both constraints are inactive for a NULL class. |
| `book_requests` | `book_id`: `books.id` / `(school_id,id)`; `user_id`: `users.id` / `(school_id,id)` | CASCADE / CASCADE | Same outcomes. |
| `staff_records` | `user_id`: `users.id` / `(school_id,id)` | CASCADE / CASCADE | Same outcome. |
| `payments` | `my_class_id`: `my_classes.id` / `(school_id,id)` | CASCADE / CASCADE | Same outcome; NULL class remains allowed. |
| `payment_records` | `payment_id`: `payments.id` / `(school_id,id)`; `student_id`: `users.id` scalar plus student profile composite | CASCADE / CASCADE | Same outcomes; student deletion can reach records directly and through profile. |
| `receipts` | `pr_id`: `payment_records.id` / `(school_id,id)` | CASCADE / CASCADE | Same outcome. |
| `time_table_records` | `my_class_id`, nullable `exam_id`: original parent ID plus same-school key | CASCADE / CASCADE | Same outcomes; NULL exam remains allowed. |
| `time_slots` | `ttr_id`: `time_table_records.id` / `(school_id,id)` | CASCADE / CASCADE | Same outcome. |
| `time_tables` | `ttr_id`, `ts_id`, nullable `subject_id`: original parent ID plus same-school key | CASCADE / CASCADE | Same outcomes; NULL subject remains allowed. Deleting a timetable record can reach rows directly and through its time slots. |
| `promotions` | `student_id`: `users.id` scalar plus student profile composite; `from_class`, `to_class`, `from_section`, `to_section`: original parent ID plus same-school key | CASCADE / CASCADE | Same outcomes; deleting a class or student can reach a promotion along multiple paths, all with the same delete result. |
| `attendance_records` | `student_id`: `users.id` scalar plus `student_records(school_id,user_id)` composite | CASCADE / CASCADE | Same outcome; school is NOT NULL. Session deletion separately cascades records through `attendance_session_id`. |

Some cascade paths converge on a child row: deleting a class can reach student, mark, exam, or promotion rows through more than one relationship; deleting a student user can reach marks/exam/payment/promotion/PIN data both directly and through its student profile; timetable deletion can reach timetable rows through both the direct record and time-slot path. These are redundant paths, not different requested outcomes: all involved actions cascade. No pair has contradictory SET NULL versus CASCADE or SET NULL versus NO ACTION behavior. Whether PostgreSQL executes these converging paths cleanly remains an engine-level test, not established by this static review.

The `pins_student_requires_school` check is a deliberate exception to general nullable ownership: it permits a schoolless PIN while `student_id` is NULL, but requires a school once a student is assigned. That matches the source PIN creation/verification lifecycle already documented above; it does not make school ownership mandatory for unused PINs.

The migration 31 attendance relationships are translated as same-school composites without parallel scalar companions because the relevant rows have `school_id NOT NULL`: `attendance_sessions.my_class_id`, `section_id`, `academic_year_id`, `term_id`, and `marked_by`, plus `attendance_records.attendance_session_id`. Each keeps its source delete result (CASCADE for class/section/session, SET NULL for optional year/term/marker), while composites add same-school enforcement. Attendance records also retain the original scalar `student_id → users.id` and add the profile composite.

The remaining post-2019 scalar FKs were also compared against their source migrations: migration 30's 24 `school_id → schools.id ON DELETE RESTRICT` constraints remain scalar; migration 31's school/academic-year/term/attendance actions are retained as enumerated in the inventory above; migration 32's audit `school_id` and `actor_id` SET NULL FKs remain scalar; migration 33's role/permission/user-permission CASCADE and grantor SET NULL FKs remain scalar. These have no changed `ON UPDATE` action (default NO ACTION). The migration 31 attendance composites that stand in for scalar class/section/period/marker/session FKs have non-null school ownership and preserve their original delete actions.

### Column-targeted `ON DELETE SET NULL`

Every target column was checked for nullability and ownership preservation. The SQL sets only the relationship ID to NULL; it does not null the row's `school_id`:

| Child FK | Targeted NULL column | Assessment |
|---|---|---|
| `my_classes.(school_id,class_type_id)` | `class_type_id` | Nullable; keeps class ownership. |
| `sections.(school_id,teacher_id)` | `teacher_id` | Nullable; keeps section ownership. |
| `student_records.(school_id,my_parent_id)` | `my_parent_id` | Nullable; keeps student ownership. |
| `student_records.(school_id,dorm_id)` | `dorm_id` | Nullable; keeps student ownership. |
| `student_records.(school_id,academic_year_id)` | `academic_year_id` | Nullable target-added period link; keeps student ownership. |
| `marks.(school_id,grade_id)` | `grade_id` | Nullable; the matching scalar SET NULL FK has the same target-column effect. |
| Legacy period links on `exams`, `marks`, `exam_records`, `payments`, `payment_records`, `receipts`, and `time_table_records` | `academic_year_id` or `term_id` | Nullable target-added links; school ownership is preserved. |
| `promotions` period links | `from_academic_year_id`, `to_academic_year_id` | Both nullable; school ownership is preserved. |
| `attendance_sessions` period/marker links | `academic_year_id`, `term_id`, `marked_by` | All nullable; required school ownership is preserved. |

The standalone source SET NULL constraints on `users` lookup IDs, `sections.teacher_id`, `student_records.my_parent_id`/`dorm_id`, `marks.grade_id`, audit IDs, and `user_permissions.granted_by` target their single nullable FK column and do not affect ownership. No column-targeted SET NULL references a NOT NULL local relationship column in the draft. This is a source/DDL inspection, not PostgreSQL parser validation.

### Verdict and remaining verification

**Static referential-action review: consistent.** No contradictory action pair, wrong target, unintended nullable-school requirement, or ownership-clearing column-list SET NULL was found. The source scalar FKs retain their original target/delete behavior; where the later attendance migration uses composite-only translations, its school key is required and its delete action is unchanged. The scalar duplicates are logically redundant on school-owned rows but preserve source checks for ownerless rows; converging cascade and SET NULL paths have the same end result.

**Deployment verdict: not ready to apply or production-deploy.** The proposal is ready for the next isolated PostgreSQL 17 compatibility test only. That test must verify migration parsing, the column-list SET NULL forms, and converging cascade/SET NULL behavior with representative temporary rows. No PostgreSQL validation or Supabase execution is claimed here.
