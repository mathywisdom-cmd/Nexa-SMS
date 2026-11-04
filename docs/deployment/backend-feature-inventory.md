# Laravel backend feature inventory

**Scope:** static inventory of routes, controllers, middleware, models, services, requests, notifications, events, jobs, views and migrations. This list includes routes outside the visible menu. It is not proof of successful behavior. No code was changed.

## Existing architecture

- Laravel 8.42.1; Blade web routes in `routes/web.php`; only one generic `auth:api` user route in `routes/api.php`.
- Main controller families: `Auth\*`, `HomeController`, `MyAccountController`, `SuperAdmin\{Dashboard,School,Setting,RolePermission,AuditLog}Controller`, `SupportTeam\*Controller`, `MyParent\MyController`, `AjaxController`.
- Authorization: legacy `user_type` middleware plus `PermissionService`/`RequirePermission`; platform/school permissions have prefixes. Tenant host resolution and `TenantContext` set school context; `BelongsToSchool` is the model scope/creation hook. Platform owner behavior permits rows with null `school_id` in scoped models.
- Validation is mixed between `app/Http/Requests/**` and inline `$request->validate()`/`request()->validate()`.
- `EventServiceProvider` maps Laravel Registered to email verification. Password resets use `NexaSmsPasswordReset`. No application event listeners beyond this registration mapping were found. `app/Console/Kernel.php` has no scheduled jobs; no `ShouldQueue` job classes or dispatch usage were found in the inspected app. Queue default is `sync`.

## Feature-by-feature inventory and target mapping

There are **35 inventory entries** below, covering distinct platform, portal, and workflow capabilities. Some adjacent capabilities are combined in one row; “preserve” means preserve the current behavior/visual intent through a rewrite, not copy Laravel implementation unchanged.

| # | Existing feature and source evidence | Main data | Target implementation / rewrite note |
|---:|---|---|---|
| 1 | Login, logout, email/username identity: `Auth::routes()`, `LoginController` | `users`, Auth identity | Supabase Auth; username alias compatibility needs a protected resolver or planned username-to-email migration. |
| 2 | Password recovery/reset and email verification: Auth controllers, `NexaSmsPasswordReset`, `Registered` listener | `password_resets` currently; Auth users | Supabase Auth recovery/verification and templates/redirect URLs. Laravel reset table becomes obsolete after cutover. |
| 3 | Generic user registration: `RegisterController`, exposed by `Auth::routes()` | users | Audit before preserving: create() writes name/email/password but not required legacy `code`/`user_type`/school membership, so this public route is not a complete tenant-safe onboarding workflow. |
| 4 | User self-profile/photo/password: `MyAccountController`, `my_account` routes | users | Profiles + Auth password APIs; Storage. Preserve validation and school context. |
| 5 | Platform owner dashboard and aggregate reports: `DashboardController`, `PlatformDashboardService` | schools, users, audit_logs | Platform-only views/query/RPC; ensure owner access is explicit, never inferred from client role claims. |
| 6 | School registration/create/update/status and first admin: `SchoolController` | schools, users | Transactional privileged provisioning workflow; edge function required to create Auth identity and linked school admin atomically/recoverably. |
| 7 | School host/tenant selection: `SchoolHostResolver`, `InitializeTenantContext`, `TenantContext` | schools.slug/status | Host-to-school map plus canonical membership lookup; RLS on every tenant table; Vercel wildcard/custom domain behavior needs routing work. |
| 8 | Roles, permissions, user permission overrides: `RolePermissionController`, `PermissionService`, `RequirePermission` | roles, permissions, role_permissions, user_permissions, users.user_type | Preserve exact permission strings and role grants. Store role/membership server-side; deny client mutation of role, `school_id`, permission grants. |
| 9 | School settings/branding and platform settings: `SettingController`, settings views; school fields/logo | settings, schools | Tenant-scoped settings and Storage. Clarify school setting model vs platform configuration; don't merge brands. |
| 10 | School dashboard/counts/recent payments/activity | multiple tenant entities | RLS-protected queries or read-only views/RPC; views must not bypass RLS. |
| 11 | Students: CRUD, detail, graduated, reset password, class list: `StudentRecordController` routes/views | users + student_records | Preserve user/record split or normalize carefully; guard auth identity/admin actions. |
| 12 | Student promotions/reset promotion: `PromotionController` | promotions, class/section, academic years | Transactional DB function where multi-row promotion needs atomicity; enforce both source/target same school. |
| 13 | Parent/guardian and parent child portal: `MyParent\MyController`, parent IDs on student_records | users/student_records | Explicit guardian-child relation RLS; parent only sees linked students, not all school students. |
| 14 | Teacher and staff management: `UserController`, `StaffRecordController`, support views | users, staff_records | Auth identity + school profile/membership; role grants controlled by school admins with constraints. |
| 15 | Classes and class types | class_types, my_classes | Tenant tables, composite tenant keys/foreign keys. |
| 16 | Sections and teacher assignment | sections, users | Tenant-consistent FK to class and same-school teacher. |
| 17 | Subjects and teacher assignment | subjects, users | Tenant-consistent FK to class and same-school teacher. |
| 18 | Academic years and terms | academic_years, terms | Tenant tables, unique `(school_id,name)` and composite `(school_id,academic_year_id)` link; atomic current-year updates. |
| 19 | Attendance entry, update, history, statuses, report, print, student history | attendance_sessions, attendance_records, students | RLS plus atomic roster validation; check a student's school/class/section on every write. Routes include `/attendance/student/{student}` outside the route-level permission group. |
| 20 | Exams and grade scales | exams, grades, class_types, academic periods | Tenant constraints, term/year relationship validation. |
| 21 | Marks/results, comments/skills, bulk fix/update, tabulation, PIN-gated student show/print | marks, exam_records, skills, pins | Preserve separate view/manage permissions, teacher assignment rules, same-school joins and PIN attempts/verification. Multiple read/print endpoints are distinct from the main marks index. |
| 22 | Fees/payment schedules, class/year selection and invoice | payments | Tenant-scoped fee definitions and constraints. Current route naming is school payment records, not online card processing. |
| 23 | Payment ledger, pay-now, receipts, receipt/PDF views, reset record | payment_records, receipts | Use transactional SQL function/RPC for balance decrement, idempotent payment, and receipt numbering. Preserve audit records and duplicate-payment protection. |
| 24 | Library books and book requests | books, book_requests | Tenant-scoped CRUD/request status and borrower relationship. |
| 25 | Dormitories and assignments | dorms, student_records | Tenant-scoped CRUD and same-school student FK. |
| 26 | Timetables, timetable records, slots, class/subject assignment, show/print | time_tables, time_table_records, time_slots | Tenant-consistent FKs, uniqueness/conflict constraints, preserve read/print and write permissions. |
| 27 | Location/reference lookup endpoints: states, LGAs, nationalities, blood groups; `AjaxController` | reference tables | Shared catalogs can be read-only; data API grants should be minimal. |
| 28 | Audit logging and platform/school audit listings | audit_logs | RLS and append-only insert policy; prevent tenant admins from changing/deleting audit rows; platform audit isolated. |
| 29 | Photo/logo/document uploads and receipt PDFs | path strings in users/settings/schools; object storage | Supabase Storage policy tied to membership and object path; private student files; signed URLs. |
| 30 | PIN generation, issue/list/delete/verify | pins | Tenant-scoped secret values; hash PINs at rest, rate-limit verification, never expose PIN lists to client broadly. |
| 31 | Legal public pages and platform contact | Blade legal pages | Static Vercel routes; no school session requirement. |
| 32 | Email notifications | reset/verification notifications | Supabase Auth email templates/SMTP; no other notification delivery workflow discovered. |
| 33 | Terms, settings, session/currency and school-specific branding | settings/schools | Persist per school and resolve only through verified host/membership. |
| 34 | Seed/reference data and Uganda locations | `database/seeders/**`; migration inserts | Convert reference seeders carefully; do not import `DatabaseSeeder` sample dataset automatically. |
| 35 | API identity endpoint and test/inspire utilities | `/api/user`, `TestController`, console inspire | Replace API user with verified Auth/profile bootstrap; test routes/inspire are not product features and should not be exposed in production without an explicit need. |

Use the 35-row inventory above as the migration tracker. There is no dedicated general ReportsController; report views are generated within attendance, marks, timetable and dashboard workflows. There is no implemented subscription/billing backend despite a `platform.subscriptions.manage` permission label.

## Unmenued or easily missed routes

`routes/web.php` includes student password reset, graduated/not-graduated, class roster, promotion selector/manage/reset/reset-all/promote, user reset password, timetable record/slot management and deletion, PIN enter/verify/delete, mark batch fix/update, mark selector/year selector/show/print, tabulation print, attendance student history, payment selection/reset/pay-now/receipt PDF, three dependent AJAX lookups, generic Auth registration/verification, parent children, platform school edit and permission update. These must be included in parity and authorization tests even if not linked in menus.

## Migration checklist

- [ ] Inventory and document current route/role behavior, including concealed and direct URL routes.
- [ ] Map each legacy account to one Auth identity and one or more school membership records.
- [ ] Preserve all feature behaviors above with route-by-route acceptance tests.
- [ ] Replace Blade/controller validation, redirects and flash errors with typed frontend/DB response states.
- [ ] Verify RLS for every read/write path, relationship, export, print and Storage object.
- [ ] Verify transactional payment/receipt, promotion, attendance and school-admin provisioning.
- [ ] Test role/permission matrix and cross-school ID manipulation.
- [ ] Reconcile legal/auth/email redirects, file references and audit history.
- [ ] Remove or explicitly disable obsolete Laravel routes only after parity approval and data migration signoff.

## Existing jobs and notifications

`app/Console/Kernel.php` has an empty scheduler; `routes/console.php` contains the stock `inspire` command. Static search found no queued job classes or application `dispatch()` calls. Mail is used for Laravel password reset and email verification. Do not add a queue worker as a prerequisite unless the converted app introduces asynchronous jobs or notification delivery.
