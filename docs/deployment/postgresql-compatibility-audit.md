# PostgreSQL compatibility audit

Audit date: 2026-11-04  
Repository: `deployment/vercel-supabase-poc`, baseline `1f278ee774b0946dad1d5f1f548702feea3b8b57`  
Scope: static review only. No PostgreSQL server was available, so no PostgreSQL migration or application test has passed.

## Summary

**Status: incompatible until specific migration defects are resolved and a real PostgreSQL run passes.** Laravel 8.42.1 has a PostgreSQL connection grammar and the application uses mostly Laravel schema/query-builder APIs. That is evidence of potential compatibility, not proof that this project's migrations or queries run on PostgreSQL.

### Confirmed risks and blockers

| Finding | Classification | Files | Effect / next action |
|---|---|---|---|
| String fields use integer literal defaults. | **Likely PostgreSQL migration blocker; untested against a server.** | `database/migrations/2018_10_06_225007_create_pins_table.php` (`used`, `times_used`); `database/migrations/2018_10_31_192540_create_book_requests_table.php` (`returned`) | Laravel's PostgreSQL DDL may render a varchar default as an unquoted numeric expression, which PostgreSQL can reject as a type mismatch. Confirm with `php artisan migrate` on a disposable PostgreSQL database; correct the column type/default in a later authorized migration/code phase, not by rewriting deployed history. |
| PostgreSQL connection forces `sslmode=prefer`. | **Requires code/config change for a deterministic hosted TLS policy.** | `config/database.php` (`pgsql.sslmode`) | Make SSL mode configurable (for example `DB_SSLMODE=require`) and verify the actual Supabase endpoint's certificate/TLS requirements. Do not treat `prefer` as a production security decision. |
| The migration `2019_09_22_142514_create_fks.php` has an empty `down()` method. | **Rollback limitation.** | `database/migrations/2019_09_22_142514_create_fks.php` | A rollback will not remove its foreign keys. Fresh forward migration is the relevant compatibility test; rollback safety needs an explicit migration repair strategy. |
| Legacy and SaaS migrations have not been executed on PostgreSQL. | **Untested.** | All 35 migrations listed below | No deployment should run `migrate` against production until a disposable PostgreSQL run succeeds and upgrade/backfill behavior is reviewed. |

## Migration-by-migration review (35)

“Standard Laravel DDL” means no explicit MySQL/SQLite-only SQL was found in static review; it does not mean verified PostgreSQL execution.

| Migration | Static assessment |
|---|---|
| `2013_09_20_121733_create_blood_groups_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2013_09_22_124750_create_states_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2013_09_22_124806_create_lgas_table.php` | Standard DDL; FK is deferred to `2019_09_22_142514_create_fks.php`. |
| `2013_09_26_121148_create_nationalities_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2014_10_12_000000_create_users_table.php` | Standard Laravel schema-builder DDL; integer/signedness mappings need runtime confirmation. |
| `2014_10_12_100000_create_password_resets_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2018_09_20_100249_create_user_types_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2018_09_20_150906_create_class_types_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2018_09_22_073005_create_my_classes_table.php` | Standard Laravel schema-builder DDL; FK deferred. |
| `2018_09_22_073526_create_sections_table.php` | Standard Laravel schema-builder DDL; FKs deferred. |
| `2018_09_22_080555_create_settings_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2018_09_22_081302_create_subjects_table.php` | Standard Laravel schema-builder DDL; FKs deferred. |
| `2018_09_22_151514_create_student_records_table.php` | Standard DDL; student fields and FKs need runtime confirmation. |
| `2018_09_26_124241_create_dorms_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2018_10_04_224910_create_exams_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2018_10_06_224846_create_marks_table.php` | Standard Laravel schema-builder DDL; FKs deferred. |
| `2018_10_06_224944_create_grades_table.php` | Standard Laravel schema-builder DDL; FK deferred. |
| `2018_10_06_225007_create_pins_table.php` | **Risk:** `string('used')->default(0)` and `string('times_used')->default(0)`; see blocker above. FKs deferred. |
| `2018_10_18_205550_create_skills_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2018_10_18_205842_create_exam_records_table.php` | Standard Laravel schema-builder DDL; FKs deferred. |
| `2018_10_31_191358_create_books_table.php` | Standard Laravel schema-builder DDL; FK deferred. |
| `2018_10_31_192540_create_book_requests_table.php` | **Risk:** `string('returned')->default(0)`; see blocker above. FKs deferred. |
| `2018_11_01_132115_create_staff_records_table.php` | Standard Laravel schema-builder DDL; FK deferred. |
| `2018_11_03_210758_create_payments_table.php` | Standard Laravel schema-builder DDL; FK deferred. |
| `2018_11_03_210817_create_payment_records_table.php` | Standard Laravel schema-builder DDL; FKs deferred. |
| `2018_11_06_083707_create_receipts_table.php` | Standard Laravel schema-builder DDL; FK deferred. |
| `2018_11_27_180401_create_time_tables_table.php` | Standard Laravel schema-builder DDL; FKs deferred. |
| `2019_09_22_142514_create_fks.php` | Foreign keys are added after table-creation migrations, so creation order is deliberate and tables exist by then. No direct circular FK cycle identified in the declared edges. Empty `down()` is a rollback limitation. PG type compatibility of each referencing/referenced key still requires execution. |
| `2019_09_26_132227_create_promotions_table.php` | Standard Laravel schema-builder DDL; untested on PG. |
| `2026_09_27_000000_create_schools_and_assign_school_ownership.php` | Creates the `schools` table and assigns tenant ownership/backfills legacy tables using Laravel schema/query-builder operations. Review hard-coded legacy unique-index names and existing-schema assumptions against the actual upgrade path; no MySQL SQL found. |
| `2026_09_27_000001_create_academic_periods_and_attendance_tables.php` | Laravel schema-builder tables, composite uniqueness/FKs, and nullable/cascade constraints; structurally plausible on PG, but composite referenced-key and delete behavior are untested. |
| `2026_09_27_000002_create_audit_logs_table.php` | Includes JSON properties and tenant FK/indexes. PostgreSQL supports JSON; query/operator behavior is untested. |
| `2026_09_27_000003_create_roles_and_permissions.php` | Schema builder plus intentional role/permission inserts in the migration. Insert syntax uses Laravel DB APIs; no driver-specific SQL found. |
| `2026_09_27_000004_link_legacy_records_to_academic_periods.php` | Data backfill and dynamic schema checks via Laravel APIs. PG compatibility is plausible but not tested; runtime duration/locking and idempotency on representative legacy data need measurement. |
| `2026_09_28_000000_add_slug_to_schools_table.php` | Adds/backfills a slug with Laravel APIs; uniqueness and duplicate legacy data need a disposable-data test. |

## Database and query layer

- `composer.lock` identifies the framework as Laravel 8.42.1; `composer.json` permits PHP `^7.2||^8.0` and Laravel `^8.40`. The PostgreSQL connection is defined in `config/database.php`; MySQL remains the default unless `DB_CONNECTION` is set.
- The checked-in Laravel `PostgresConnector` uses PDO, applies `SET NAMES`, `SET search_path`, optional timezone/application name and synchronous commit. Its own default PDO options omit `PDO::ATTR_EMULATE_PREPARES`; the inherited connector default is `false`. Supabase transaction pooling does not support prepared statements, so the current Laravel/PDO behavior is a **pooler compatibility risk**: use a compatible connection mode or explicitly configure/test the driver behavior before using the shared transaction pooler.
- `pdo_pgsql` must be installed and enabled in the PHP runtime; this cannot be verified here. `config/database.php` currently hard-codes `sslmode` as `prefer` rather than reading an environment variable.
- Application SQL is primarily Eloquent/Query Builder. `app/Services/PlatformDashboardService.php` contains portable-looking aggregates (`COUNT`, `SUM(CASE...)`, `COALESCE`, `MAX`), subqueries, `whereNotExists`, and Laravel JSON path predicates against `audit_logs.properties`. PostgreSQL support is plausible; no live query validation was possible.
- Common query usage includes `whereDate`, `LIKE`, `whereIn`, `whereNull`, `groupBy`, pagination and timestamps. PostgreSQL `LIKE` is case-sensitive by default, unlike many MySQL collations; search semantics may change. Inspect user-visible searches and decide whether that is acceptable before release.
- No database-specific raw SQL was found in migrations or seeders. The service raw expressions above use common SQL; application query audit remains static and not exhaustive proof of driver behavior.
- Laravel's `DB::transaction` and normal bound query builder operations are in use. No explicit transaction isolation/session pinning was found in application code. The connection's `SET search_path` is per connection and may be reset/reassigned by poolers; test schema selection and pooler behavior.
- JSON is created as Laravel `json` for `audit_logs.properties`, cast by the model, and queried with Laravel JSON path syntax. PostgreSQL JSON operators are available in principle, but this Laravel version's emitted SQL and Supabase schema need execution tests.
- Indexes and constraints are primarily generated through Laravel's schema builder. No explicit duplicate index names were found in the reviewed migration source. PostgreSQL's 63-byte identifier limit, actual generated names, FK integer type compatibility, and unique-index replacement logic in the school-ownership migration were not programmatically verified against PostgreSQL.
- Migration seeds: `2026_09_27_000003_create_roles_and_permissions.php` inserts platform role/permission reference records. Inspect `database/seeders` separately before any seeding: `DatabaseSeeder` includes sample-oriented seeders and should not be run just to test schema compatibility.

## Local PostgreSQL test result

**Not run.** `docker`, `psql`, `pg_ctl`, `postgres`, `initdb`, `php`, and `composer` were not available on PATH, and no PostgreSQL Windows service was found. The previously used portable PHP executable was also unavailable at its old path. No database was created, contacted, or migrated. SQLite results must not be substituted for this test.

## Required next test

Provision a disposable local PostgreSQL instance and a fresh disposable database. Install PHP `pdo_pgsql`, use the project's pinned PHP/Laravel environment, configure TLS consistently with the target, and run the complete migration chain with `php artisan migrate --force` against only that disposable database. Capture the first failure and continue only after resolving it. Then run PHPUnit and targeted login, tenant-isolation, JSON, date/search, and pagination checks against PostgreSQL. Keep production/Supabase disconnected until those tests pass.
