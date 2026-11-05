# Supabase RLS design

Status: design based on Laravel routes, middleware, `PermissionService`, models and tenant relationships. This is not a claim that policies have been deployed or verified. The proposed SQL migration starts with deny-by-default policies for sensitive rows; the full application API must be implemented through scoped policies and trusted RPC/Edge operations before frontend cutover.

## Security invariants

1. `auth.uid()` is the only identity root. Resolve it to `public.users.auth_user_id`; never accept a role or `school_id` as authority from browser JSON, query parameters, or mutable user metadata.
2. A tenant-scoped operation requires an active membership/profile with a non-null `school_id`. The existing data model has one `school_id` per user and school-local `user_type`; it does not have a many-school membership table. Do not introduce one without a product decision.
3. Super-admin accounts have `school_id IS NULL` and platform permissions. They must not gain school-row access merely because the tenant is NULL; platform operations need distinct permission-checked server functions.
4. Every school-owned row must match both the authenticated user's school and the related row's school. Add composite unique keys and FKs for same-school relationships wherever the legacy FK is currently only scalar.
5. Updates must preserve ownership: both `USING` and `WITH CHECK` enforce the same school. Client roles may not write `school_id`, `user_type`, `auth_user_id`, password hashes, role grants or permission catalog rows.
6. No public/anon access to student, guardian, staff, grade, mark, payment, receipt, PIN, audit, identity or school configuration records.

## Authentication and authorization mapping

Create a nullable unique `users.auth_user_id uuid REFERENCES auth.users(id) ON DELETE CASCADE`; preserve `users.id` integer identity for all legacy relations. A private helper can map `auth.uid()` to a profile, and permission evaluation can mirror `PermissionService`: apply `user_permissions` override first, otherwise role grant from `user_type`/`roles`/`role_permissions`; platform permission names begin `platform.`. Do not use `raw_user_meta_data` or `user_metadata` as trusted authorization claims. Restrict helper execution and use a fixed search path; prefer invoker functions where RLS permits. If `SECURITY DEFINER` is required to avoid recursive policy lookups, put it in a non-exposed schema, qualify every object, set `search_path = ''`, revoke default PUBLIC execute, and grant only required roles.

User provisioning, invitation, first-school-admin creation and role changes are privileged operations. They should be invoked by a server-side Edge Function using verified JWT identity and narrow database functions, or by a trusted operator flow. A browser must not write `users.user_type`, `users.school_id`, `role_permissions`, or `user_permissions`. A database function cannot atomically create a Supabase Auth identity through the Auth Admin API; Edge Function coordination and compensating cleanup are required.

## Data access matrix

“Scoped access” below means a policy checks both permission and school membership, with relationship predicates for non-admin roles. All unmentioned reads/writes are denied. Backend-only does not mean service-role use in the browser.

| Tables | Anonymous | Authenticated school roles | Platform owner / privileged server |
|---|---|---|---|
| `blood_groups`, `states`, `lgas`, `nationalities`, `user_types` | Read only if these values are intentionally public signup choices; otherwise deny | Read lookup rows | Manage only through trusted migration/admin |
| `schools` | No direct access; public school discovery only via a deliberately limited published view/function if required | Read own school’s non-secret display fields; update only with `settings.manage` / school-admin policy | Platform `platform.schools.manage` server operation |
| `users` | Deny | Read minimal same-school profiles; self profile read/update allowlist; student/parent visibility only through linked relationships | Provision/update user through trusted server; never expose hashes/remember token |
| `password_resets` | Deny | Deny; Supabase Auth handles recovery | No normal API access; retire after cutover |
| `class_types`, `my_classes`, `sections`, `subjects`, `grades`, `dorms`, `settings`, `skills`, `books` | Deny | Own-school read; CRUD only with corresponding permission; teacher read constrained by assignment where applicable | Platform support via trusted operation only |
| `student_records` | Deny | Admin/support read/manage own school with permission; teacher only assigned section; student self; parent only linked child | Platform support through audited privileged path |
| `staff_records` | Deny | Own school and `teachers.view/manage` or self profile rules | Privileged only |
| `exams`, `marks`, `exam_records`, `pins` | Deny | School staff only with `exams.*` / `marks.*`; students/parents must use protected results operation; PIN table direct access deny | PIN validation/results operation server side; platform access audited |
| `academic_years`, `terms` | Deny | Own school with relevant manage/read permission | Privileged only |
| `attendance_sessions`, `attendance_records` | Deny | Own school, `attendance.view/mark`; teacher additionally assigned to section; student/parent only personal history through safe function | Privileged audited operations |
| `payments`, `payment_records`, `receipts` | Deny | Own school with `fees.view/manage` and `payments.record`; student/parent payment view only their linked records if product requires; no direct receipt creation | Payment transaction function; platform reports via privileged operation |
| `book_requests` | Deny | Own-school and relationship/`library.manage` rules; borrowers see own requests | Trusted/admin only |
| `time_table_records`, `time_slots`, `time_tables` | Deny | Own school; teacher scope if assigned; manage permission needed for writes | Privileged only |
| `promotions` | Deny | Own school with student management permission; no user-supplied ownership | Transactional promotion operation if multi-row behavior is required |
| `audit_logs` | Deny | Read own school only with `audit_logs.view`; no direct insert/update/delete | Append via protected audit function; platform logs via platform permission |
| `roles`, `permissions`, `role_permissions`, `user_permissions` | Deny | Read-only effective permissions may be exposed via a narrow function; no grants writes | Role assignment only via privileged, audited server operation |

Role mapping uses the existing migration seed, not new roles: `super_admin`, `admin`, `principal`, `head_teacher`, `teacher`, `accountant`, `bursar`, `librarian`, `staff`, `parent`, `student`. Application-specific policies must honor the seeded grant matrix and overrides; do not collapse them to a broad “authenticated” role. Notably accountant/bursar have fee/payment permissions but not marks permissions; librarian has `library.manage` and not marks permissions.

## Relationship predicates to preserve

- Student identity: `student_records.user_id = current profile id`.
- Parent: `student_records.my_parent_id = current profile id`; expose only linked child records.
- Teacher: `sections.teacher_id = current profile id` for homeroom workflows; `subjects.teacher_id = current profile id` for subject/marks workflows. Validate class/section/subject/exam/student all share school and expected relationships.
- Attendance: a user who can mark attendance must be permitted for the requested section; submitted roster must equal the server-side current roster, matching `AttendanceController` behavior.
- Marks: staff read/manage requires `marks.view`/`marks.manage`; `MarkController` has an additional PIN/session gate for student-facing result access. Since a Supabase JWT/RLS policy does not inherit Laravel session `pin_verified`, do not grant student/parent direct `marks` or `exam_records` reads until a protected function/Edge operation reproduces the PIN semantics.
- Payments: validate target payment record belongs to same school and student; amount must be positive and not exceed balance; update balance/paid state, insert receipt, and append audit atomically.

## Storage buckets and policies (implementation remains pending)

- School logos and public branding images can use a dedicated public bucket only if the existing asset semantics permit public reads; writes require school-admin permission and object path prefix equal to verified school ID.
- User/profile and student photographs may contain personal data; use private bucket by default and signed URLs. A public bucket requires explicit privacy decision.
- Student documents, receipts and other financial/education records must be private. Policies must resolve object path to school and record ownership rather than trust arbitrary path segments. Receipts should be tied to `receipts` / `payment_records` rows.
- Do not create a bucket or policy until names, existing upload paths, public/private access, retention and migration strategy are confirmed. No files were copied or storage configured in this task.

## Verification cases required before launch

Use two disposable schools and accounts created through the actual provisioning flow. Run through Supabase Auth JWTs; never use the service-role key for these checks.

| Actor/action | Expected |
|---|---|
| anon select/insert on every sensitive table | denied |
| authenticated user with no profile/school | no school data, all writes denied |
| School A admin select School A rows | allowed only for granted permission |
| School A admin select/update School B row by ID | zero rows / denied; no data change |
| School A user insert or update with School B `school_id` | denied |
| accountant/librarian select/manage marks | denied absent marks permission |
| teacher access assigned vs unassigned section/subject | assigned allowed by permission, unassigned denied |
| student self row vs another student | own allowed only via intended portal/function; other denied |
| parent linked child vs unrelated student | linked only; unrelated denied |
| user changes own `user_type`, role or permission grant | denied |
| user changes `school_id` or `auth_user_id` | denied |
| client reads password, reset token, PIN code or audit properties without permission | denied |
| staff payment recording | transaction succeeds only within school, no overpayment, receipt/audit committed together |
| attendance with forged student ID | entire batch rejected; no partial write |
| school A private object fetches School B object | denied |

No SQL policy tests were executed here because no PostgreSQL environment or Supabase connection was permitted.
