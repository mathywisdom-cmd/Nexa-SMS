# Frontend migration plan: Laravel Blade to Vercel

**Status:** assessment only. No frontend code, dependencies, or routes were changed.  
**Source reviewed:** `routes/web.php`, `routes/api.php`, `resources/views/**`, `resources/js/**`, `resources/sass/**`, `public/**`, `package.json`, `webpack.mix.js`.

## Current frontend findings

- The operational UI is Laravel Blade, rendered by controllers. `resources/views` includes auth and legal pages, shared master/login layouts, role-specific menus, owner/admin pages, and school workflows for students, users/staff, academic data, attendance, exams/marks, payments/receipts, library, dorms, and timetables.
- `resources/js/components/ExampleComponent.vue` is the only Vue component found. There is no existing Vue application/router or feature component set to carry the school system. Vue 2.5.7 is listed in `package.json`, with Laravel Mix 2.x; this does not constitute a reusable SPA.
- The Blade forms submit to Laravel web routes, expect Laravel CSRF tokens, redirect/flash messages, server-side validation errors, Blade-generated URLs, and session-authenticated controller actions. These pages cannot be deployed as static pages unchanged.
- `resources/js` bootstraps Axios, Bootstrap, and Vue; the main layouts include legacy template scripts/plugins (jQuery, DataTables, date/select/form/file upload widgets, charts and icon assets). Some static CSS, images, icons, and the general visual structure can be reused after checking licenses and dependency compatibility.
- `routes/api.php` only defines Laravel's `/api/user` endpoint using `auth:api`; it is not an application API for the Blade workflows. There is no existing JSON backend that a Vercel frontend can call.
- Login, logout, password reset, account updates, role-specific dashboards, portals, search/lookups, lists, edit pages, reports/prints, and every form currently rely on Laravel routes/controllers or middleware. Browser-driven API use will require rebuilding the interaction and validation states.

## Recommended least-disruptive frontend

Use **Vue 3 + Vite as a Vercel static SPA**, with `@supabase/supabase-js` for Auth/Data API/Storage, and keep Bootstrap 4/template CSS and relevant static assets during the first conversion. Vue is already the team's listed frontend framework, but the only Vue component is the framework example; retaining Vue is a technology preference, not reuse of the application UI. Vue 3 avoids building a new product in the old Vue 2/Mix 2 stack. If the team requires smallest dependency delta over security/support lifetime, Vue 2 can be a temporary bridge, but it should not be treated as the long-term target.

This does **not** preserve Blade execution. Convert the current Blade page families into Vue pages/layout components, translate Laravel route names into client routes and Supabase queries/functions, preserve the present layouts/labels/colors, and migrate feature-by-feature. No new frontend framework is strictly required; React/Next is not justified by the current repository. A Next.js frontend would add a framework conversion with little benefit for this Laravel Blade app unless server-side rendering/SEO becomes a requirement.

Suggested SPA boundaries:

1. `Public`: NexaSMS platform landing/legal pages, login, recovery, recovery completion.
2. `Platform`: owner dashboard, school directory/create/edit, platform permissions, audit.
3. `School shell`: school branding/navigation, common tenant-aware route guards and permission map.
4. `School modules`: students/guardians/users, academic setup, attendance, assessment, fee/payment, library/dorm/timetable, reports.
5. `Portals`: parent children view; student/teacher routes as implemented by the present role/dashboard logic. Inventory routes/templates first; do not infer unimplemented portals.

Client route guards are for navigation/UX only. PostgreSQL RLS and any Edge Function checks must enforce access independently. Never authorize using a `school_id`, role, or permission supplied by local storage or form input.

## Route and page migration checklist

| Existing page/workflow | Current source | Frontend treatment |
|---|---|---|
| Login/logout, forgot/reset password, verification, generic registration | `Auth::routes()`; `resources/views/auth/**`; `Auth\*Controller` | Rebuild pages and states with Supabase Auth. See authentication plan. Audit registration before carrying it forward. |
| Privacy policy and terms | `/privacy-policy`, `/terms-of-use`; `HomeController`; Blade legal views | Recreate as public static Vue routes or static HTML. Preserve links and platform brand. |
| Authenticated redirect/dashboard | `/`, `/home`, `/dashboard`; `HomeController` | Role-aware SPA landing; owner and school dashboards are distinct. |
| Account profile/password | `/my_account` GET/PUT, `/my_account/change_password` | Supabase profile update + Auth password update; photo upload via Storage policy. |
| School settings | `/school/settings` GET/PUT | School tenant query with RLS; logo upload to Storage. |
| Academic years/terms | `/school/academic-periods` and CRUD routes | SPA lists/forms; use DB constraints/transactions for current year and parent-child consistency. |
| Attendance | `/attendance`, history, reports, print, student history | SPA entry/roster/report views. Preserve exact roster validation and school/class/section constraints in trusted DB transaction/RLS. |
| Students | Resource routes plus graduated/list/reset-password/not-graduated/promotions | SPA list/detail/edit/create/graduated and promotion flows; sensitive password reset must use Auth admin/reset policy, not client edits. |
| Staff/users | `users` resource, reset password, account profile | SPA role-specific forms; identity provisioning is a privileged server workflow. |
| Classes/sections/subjects | Resources and AJAX lookups | Rebuild selectors; enforce class/section/school consistency server-side via FK/RLS/RPC. |
| Examinations/grades/marks | Exam/grade resources; marks index/manage/update/batch/tabulation/print/selector/year/show | Convert all read and write screens, bulk entry, selector dependencies, comments/skills, print sheets and tabulation. Permission split (`marks.view` vs `marks.manage`) must survive. |
| Fees/payments/receipts | Payment resource, invoice/manage, payment record, receipt/PDF, select class/year, reset, pay-now | Rebuild fee and ledger UI. Use transactional DB function for amount/balance/receipt number where atomicity is required. Do not imply gateway processing; existing routes record school payments. |
| PIN workflows | `pins` create/list/store/verify/delete/enter | Rebuild PIN create/verify and student result unlock behavior, preserve rate/attempt/ownership rules. |
| Library | Book and book-request controllers/views | Rebuild books and requests; carry access restrictions and dates/status. |
| Dormitories | Dorm resource and student assignment | Rebuild CRUD/assignment. |
| Timetable | Timetable, record, time-slot CRUD/manage/show/print | Rebuild all route workflows; validate slot/class/subject relationships. |
| Parent portal | `/my_children`; parent controller/view | Rebuild child list with explicit parent-student relationship authorization under RLS. |
| Platform owner | `/super_admin/**` | Rebuild owner dashboards/school CRUD/audit/permission admin. Platform access must be a separate trusted authorization boundary. |
| AJAX lookups | `/ajax/get_lga`, `get_class_sections`, `get_class_subjects` | Replace with constrained queries/RPC; never expose cross-tenant identifiers. |
| API user endpoint | `/api/user` | Remove Laravel API dependency after replacement; use Supabase `getUser` / profile query. |

## Reusable versus rewrite

**Potentially reusable:** brand palette, Bootstrap/template CSS and icon fonts, image assets, static legal copy, many HTML layouts as visual references, validation wording, labels, table/print design, and conceptual workflow.

**Must be rewritten/adapted:** all Blade syntax, Laravel route helpers, controller-driven data assembly, FormRequest validation/error bags, CSRF/method spoofing, session flash redirects, Laravel pagination response assumptions, jQuery handlers tied to Blade DOM, upload storage URLs, PDFs/print data rendering, and middleware authorization.

## Build and routing notes

Vercel should host the compiled SPA assets; route fallback must deliver the SPA entry for client-side routes without shadowing static files or API/storage origins. Establish a modern lockfile and pinned dependencies, production asset build, environment separation, CSP/security headers, per-school hostname parsing, error tracking, and browser-level parity tests before moving users. Vue 3/Vite will replace Laravel Mix; no deployment config was created in this phase.
