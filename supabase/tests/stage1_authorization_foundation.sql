-- Proposed Stage 1 verification. Run only after the reviewed migration is
-- approved and applied. It inserts no public fixtures and rolls back all work.
-- Trigger behavior is exercised on temporary tables. Real audit positive cases
-- run only when legitimate linked profiles already exist; otherwise they skip.
-- Requires a database owner session allowed to SET ROLE anon/authenticated/service_role.
-- anon/authenticated/service_role are role simulations under this owner session;
-- only a real PostgREST/API request can prove session_user=authenticator and JWT validation.

begin;

do $test$
declare
  public_tables integer;
  forced_tables integer;
  expected record;
  fn record;
  security_function_count integer;
begin
  select count(*) into public_tables
    from pg_class c join pg_namespace n on n.oid = c.relnamespace
   where n.nspname = 'public' and c.relkind = 'r';
  select count(*) into forced_tables
    from pg_class c join pg_namespace n on n.oid = c.relnamespace
   where n.nspname = 'public' and c.relkind = 'r' and c.relrowsecurity and c.relforcerowsecurity;

  if public_tables <> 40 or forced_tables <> 40 then
    raise exception 'expected 40 public tables with forced RLS; found % tables and % forced', public_tables, forced_tables;
  end if;
  if pg_has_role('authenticated','service_role','SET')
     or pg_has_role('anon','service_role','SET')
     or not pg_has_role('authenticator','service_role','SET') then
    raise exception 'unexpected SET ROLE membership for client/authenticator roles';
  end if;
  if exists (select 1 from pg_policies where schemaname = 'public') then
    raise exception 'Stage 1 must not add public RLS policies';
  end if;
  if exists (
    select 1 from pg_proc p join pg_namespace n on n.oid=p.pronamespace
     where n.nspname='public' and p.prokind='f'
       and (has_function_privilege('anon',p.oid,'EXECUTE')
         or has_function_privilege('authenticated',p.oid,'EXECUTE'))
  ) then
    raise exception 'unexpected public RPC execution surface; review role/GUC mutation risk';
  end if;
  if exists (
    select 1 from information_schema.table_privileges
     where table_schema = 'public'
       and grantee in ('anon', 'authenticated')
       and privilege_type in ('SELECT', 'INSERT', 'UPDATE', 'DELETE')
  ) then
    raise exception 'Stage 1 must not grant general public-table access';
  end if;
  if exists (
    select 1 from pg_class c join pg_namespace n on n.oid=c.relnamespace
     where n.nspname='public' and c.relkind='r'
       and (
         has_table_privilege('anon',c.oid,'SELECT') or has_table_privilege('anon',c.oid,'INSERT')
         or has_table_privilege('anon',c.oid,'UPDATE') or has_table_privilege('anon',c.oid,'DELETE')
         or has_table_privilege('authenticated',c.oid,'SELECT') or has_table_privilege('authenticated',c.oid,'INSERT')
         or has_table_privilege('authenticated',c.oid,'UPDATE') or has_table_privilege('authenticated',c.oid,'DELETE')
       )
  ) then
    raise exception 'anon/authenticated has an effective table privilege on a public table (including inherited PUBLIC grants)';
  end if;
  if not exists (
    select 1 from pg_constraint
     where conrelid = 'public.users'::regclass
       and conname = 'users_super_admin_is_platform_only'
  ) then
    raise exception 'platform-owner school boundary constraint is missing';
  end if;
  for expected in
    select * from (values
      ('public.users'::regclass, 'users_guard_authority_fields', 'private.guard_profile_authority_fields()'::regprocedure::oid, 31::smallint),
      ('public.schools'::regclass, 'schools_guard_authority_fields', 'private.guard_school_authority_fields()'::regprocedure::oid, 31::smallint),
      ('public.user_permissions'::regclass, 'user_permissions_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint),
      ('public.role_permissions'::regclass, 'role_permissions_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint),
      ('public.roles'::regclass, 'roles_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint),
      ('public.permissions'::regclass, 'permissions_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint),
      ('public.user_types'::regclass, 'user_types_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint),
      ('public.audit_logs'::regclass, 'audit_logs_actor_school_guard', 'private.check_audit_actor_school()'::regprocedure::oid, 7::smallint)
    ) as required(table_oid, trigger_name, function_oid, trigger_type)
  loop
    if not exists (
      select 1 from pg_trigger t
       where t.tgrelid = expected.table_oid
         and t.tgname = expected.trigger_name
         and t.tgfoid = expected.function_oid
         and t.tgtype = expected.trigger_type
         and t.tgenabled = 'O'
         and not t.tgisinternal
    ) then
      raise exception 'required trigger binding/timing/events/enabled state mismatch: % on %', expected.trigger_name, expected.table_oid;
    end if;
  end loop;

  select count(*) into security_function_count
    from pg_proc p join pg_namespace n on n.oid=p.pronamespace
   where n.nspname='private'
     and p.proname in ('current_profile_id','current_school_id','has_permission',
                       'check_audit_actor_school','guard_profile_authority_fields',
                       'guard_school_authority_fields','guard_authorization_catalog_writes');
  if security_function_count <> 7 then
    raise exception 'expected seven Stage 1/helper functions, found %', security_function_count;
  end if;
  for fn in
    select p.oid, p.proname, p.prosecdef, p.proconfig, r.rolname as owner
      from pg_proc p join pg_namespace n on n.oid=p.pronamespace
      join pg_roles r on r.oid=p.proowner
     where n.nspname='private'
       and p.proname in ('current_profile_id','current_school_id','has_permission',
                         'check_audit_actor_school','guard_profile_authority_fields',
                         'guard_school_authority_fields','guard_authorization_catalog_writes')
  loop
    if fn.owner <> 'postgres' then raise exception 'unexpected owner on private.%', fn.proname; end if;
    if fn.proname in ('current_profile_id','current_school_id','has_permission','check_audit_actor_school') then
      if not fn.prosecdef then raise exception 'private.% must remain SECURITY DEFINER', fn.proname; end if;
      if not ('search_path=""' = any(fn.proconfig)) or not ('row_security=off' = any(fn.proconfig)) then
        raise exception 'private.% must have fixed empty search_path and row_security=off', fn.proname;
      end if;
    else
      if fn.prosecdef then raise exception 'private.% guard must be SECURITY INVOKER', fn.proname; end if;
      if not ('search_path=""' = any(fn.proconfig)) then
        raise exception 'private.% must have fixed empty search_path', fn.proname;
      end if;
      if has_function_privilege('anon', fn.oid, 'EXECUTE')
         or has_function_privilege('authenticated', fn.oid, 'EXECUTE') then
        raise exception 'client can execute private guard function % directly', fn.proname;
      end if;
    end if;
  end loop;
  if not has_function_privilege('authenticated','private.current_profile_id()'::regprocedure,'EXECUTE')
     or not has_function_privilege('authenticated','private.current_school_id()'::regprocedure,'EXECUTE')
     or not has_function_privilege('authenticated','private.has_permission(text)'::regprocedure,'EXECUTE')
     or has_function_privilege('service_role','private.current_profile_id()'::regprocedure,'EXECUTE')
     or has_function_privilege('service_role','private.current_school_id()'::regprocedure,'EXECUTE')
     or has_function_privilege('service_role','private.has_permission(text)'::regprocedure,'EXECUTE') then
    raise exception 'authenticated helper execution privileges are missing';
  end if;
  if has_function_privilege('anon','private.current_profile_id()'::regprocedure,'EXECUTE')
     or has_function_privilege('anon','private.current_school_id()'::regprocedure,'EXECUTE')
     or has_function_privilege('anon','private.has_permission(text)'::regprocedure,'EXECUTE')
     or has_function_privilege('anon','private.check_audit_actor_school()'::regprocedure,'EXECUTE')
     or has_function_privilege('authenticated','private.check_audit_actor_school()'::regprocedure,'EXECUTE') then
    raise exception 'unexpected client execution privilege on private helpers/trigger function';
  end if;
end
$test$;

do $owner_context$
declare role_setting text;
begin
  role_setting := nullif(current_setting('role',true),'none');
  if session_user not in ('postgres','supabase_admin')
     or current_user not in ('postgres','supabase_admin')
     or coalesce(role_setting,session_user) <> session_user then
    raise exception 'verification script must start in a direct PostgreSQL owner session; observed session_user %, current_user %, role setting %', session_user, current_user, role_setting;
  end if;
end
$owner_context$;

-- Local transaction-only fixture tables exercise the trigger bodies while
-- retaining the same lack of public-table grants/policies. No public user,
-- school, permission, or audit row is inserted by this script.
create temporary table stage1_users_guard_test (
  id integer primary key, code text, username text, email text, password text,
  remember_token text, email_verified_at timestamptz, user_type text,
  school_id integer, auth_user_id uuid, display_name text
);
create trigger stage1_users_guard_test_trg
  before insert or update or delete on pg_temp.stage1_users_guard_test
  for each row execute function private.guard_profile_authority_fields();
insert into pg_temp.stage1_users_guard_test values
  (1, 'T-1', 'test-user', 'test@example.invalid', 'hash', 'token', now(), 'admin', 1,
   '00000000-0000-4000-8000-000000000001', 'Before');
grant select, insert, update on pg_temp.stage1_users_guard_test to anon, authenticated, service_role;

create temporary table stage1_authz_guard_test (id integer primary key, allowed boolean not null);
create trigger stage1_authz_guard_test_trg
  before insert or update or delete on pg_temp.stage1_authz_guard_test
  for each row execute function private.guard_authorization_catalog_writes();
insert into pg_temp.stage1_authz_guard_test values (1, true);
grant select, insert, update, delete on pg_temp.stage1_authz_guard_test to authenticated, service_role;

create temporary table stage1_schools_guard_test (
  id integer primary key, code text, slug text, status text, name text
);
create trigger stage1_schools_guard_test_trg
  before insert or update or delete on pg_temp.stage1_schools_guard_test
  for each row execute function private.guard_school_authority_fields();
insert into pg_temp.stage1_schools_guard_test values (1, 'S1', 'school-one', 'active', 'School one');
grant select, insert, update on pg_temp.stage1_schools_guard_test to authenticated, service_role;

create temporary table stage1_audit_guard_test (actor_id integer, school_id integer);
create trigger stage1_audit_guard_test_trg
  before insert on pg_temp.stage1_audit_guard_test
  for each row execute function private.check_audit_actor_school();
grant insert on pg_temp.stage1_audit_guard_test to authenticated, service_role;

create temporary table stage1_audit_fixtures as
with school_profiles as (
  select id, school_id, auth_user_id from public.users
   where user_type <> 'super_admin' and school_id is not null and auth_user_id is not null
), a as (
  select * from school_profiles order by school_id, id limit 1
), b as (
  select * from school_profiles where school_id <> (select school_id from a) order by school_id, id limit 1
), p as (
  select id, auth_user_id from public.users
   where user_type = 'super_admin' and school_id is null and auth_user_id is not null
   order by id limit 1
)
select (select id from a) as actor_a, (select school_id from a) as school_a,
       (select auth_user_id from a) as auth_a,
       (select id from b) as actor_b, (select school_id from b) as school_b,
       (select id from p) as platform_actor, (select auth_user_id from p) as platform_auth;
grant select on pg_temp.stage1_audit_fixtures to authenticated;

-- The anonymous API must remain unable to read user, school, or student data.
set local role anon;
do $anon_context$
begin
  if current_user <> 'anon' or current_setting('role', true) <> 'anon'
     or session_user not in ('postgres', 'supabase_admin') then
    raise exception 'unexpected simulated anon context: session_user %, current_user %, role setting %',
      session_user, current_user, current_setting('role', true);
  end if;
end
$anon_context$;
select set_config('request.jwt.claim.role', '', true);
select set_config('request.jwt.claims', '', true);
select set_config('request.jwt.claim.sub', '', true);
do $test$
declare
  denied boolean;
begin
  denied := false;
  begin
    execute 'select 1 from public.users limit 1';
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'anon unexpectedly read public.users'; end if;

  denied := false;
  begin
    execute 'select 1 from public.schools limit 1';
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'anon unexpectedly read public.schools'; end if;

  denied := false;
  begin
    insert into pg_temp.stage1_users_guard_test
      values (5, 'A-5', 'anon-user', 'anon@example.invalid', 'hash', null, null, 'admin', 1,
              '00000000-0000-4000-8000-000000000005', 'Anon');
  exception when raise_exception then denied := true;
  end;
  if not denied then raise exception 'anon profile creation passed without JWT claims'; end if;

  perform set_config('request.jwt.claim.role', 'service_role', true);
  perform set_config('request.jwt.claims', '{"role":"service_role"}', true);
  denied := false;
  begin
    insert into pg_temp.stage1_users_guard_test
      values (6, 'A-6', 'forged-anon', 'forged-anon@example.invalid', 'hash', null, null, 'admin', 1,
              '00000000-0000-4000-8000-000000000006', 'Forged anon');
  exception when raise_exception then denied := true;
  end;
  if not denied then raise exception 'anon caller became trusted by forging/missing JWT role settings'; end if;
end
$test$;

-- Unknown Auth identity must fail closed. Direct role, tenant, Auth-link, and
-- permission mutation attempts must be denied without requiring fixture rows.
set local role authenticated;
do $authenticated_context$
begin
  if current_user <> 'authenticated' or current_setting('role', true) <> 'authenticated'
     or session_user not in ('postgres', 'supabase_admin') then
    raise exception 'unexpected simulated authenticated context: session_user %, current_user %, role setting %',
      session_user, current_user, current_setting('role', true);
  end if;
end
$authenticated_context$;
select set_config('request.jwt.claim.sub', '', true);
select set_config('request.jwt.claim.role', '', true);
select set_config('request.jwt.claims', '', true);
do $missing_auth_claims$
declare denied boolean := false;
begin
  begin
    insert into pg_temp.stage1_users_guard_test
      values (7, 'A-7', 'missing-claims', 'missing@example.invalid', 'hash', null, null, 'admin', 1,
              '00000000-0000-4000-8000-000000000007', 'Missing claims');
  exception when raise_exception then denied := true;
  end;
  if not denied then raise exception 'authenticated database role was trusted when JWT claims were missing'; end if;
end
$missing_auth_claims$;
select set_config('request.jwt.claim.sub', '00000000-0000-4000-8000-000000000000', true);
select set_config('request.jwt.claim.role', 'authenticated', true);
select set_config('request.jwt.claims', '{"sub":"00000000-0000-4000-8000-000000000000","role":"authenticated"}', true);

do $test$
declare
  denied boolean;
  stmt text;
begin
  if private.current_profile_id() is not null then
    raise exception 'unlinked Auth UUID unexpectedly resolved to a profile';
  end if;
  if private.current_school_id() is not null then
    raise exception 'unlinked Auth UUID unexpectedly resolved to a school';
  end if;
  if private.has_permission('platform.schools.manage') is distinct from false
     or private.has_permission('students.view') is distinct from false then
    raise exception 'unlinked Auth UUID unexpectedly has a permission';
  end if;

  denied := false;
  begin
    execute 'select 1 from public.schools limit 1';
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'authenticated unexpectedly read schools'; end if;

  denied := false;
  begin
    execute 'select 1 from public.student_records limit 1';
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'authenticated unexpectedly read student records'; end if;

  denied := false;
  begin
    update public.users set user_type = 'super_admin' where false;
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'authenticated unexpectedly has user role-update privilege'; end if;

  denied := false;
  begin
    update public.users set school_id = null where false;
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'authenticated unexpectedly has user school-update privilege'; end if;

  denied := false;
  begin
    update public.users set auth_user_id = null where false;
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'authenticated unexpectedly has Auth-link update privilege'; end if;

  denied := false;
  begin
    update public.user_permissions set allowed = not allowed where false;
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'authenticated unexpectedly has permission-assignment update privilege'; end if;

  denied := false;
  begin
    insert into public.role_permissions (role_id, permission_id) values (1, 1);
  exception when insufficient_privilege then
    denied := true;
  end;
  if not denied then raise exception 'authenticated unexpectedly has role-permission insert privilege'; end if;

  -- Exercise the profile guard itself with temporary rows and ordinary client
  -- grants, so a later table grant cannot silently reopen authority fields.
  denied := false;
  begin
    insert into pg_temp.stage1_users_guard_test
      values (2, 'T-2', 'new-user', 'new@example.invalid', 'hash', null, null, 'admin', 1,
              '00000000-0000-4000-8000-000000000002', 'New');
  exception when raise_exception then denied := true;
  end;
  if not denied then raise exception 'client profile creation unexpectedly passed the guard'; end if;
  denied := false;
  begin delete from pg_temp.stage1_users_guard_test where id=1;
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client profile deletion unexpectedly passed the guard'; end if;

  denied := false;
  begin insert into pg_temp.stage1_schools_guard_test values (2, 'S2', 'school-two', 'active', 'School two');
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client school creation unexpectedly passed the guard'; end if;
  denied := false;
  begin delete from pg_temp.stage1_schools_guard_test where id=1;
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client school deletion unexpectedly passed the guard'; end if;
  denied := false;
  begin update pg_temp.stage1_schools_guard_test set id=2 where id=1;
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client school ID change unexpectedly passed the guard'; end if;
  denied := false;
  begin update pg_temp.stage1_schools_guard_test set code='OTHER' where id=1;
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client school code change unexpectedly passed the guard'; end if;
  denied := false;
  begin update pg_temp.stage1_schools_guard_test set status='suspended' where id=1;
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client school activation/suspension change unexpectedly passed the guard'; end if;
  denied := false;
  begin update pg_temp.stage1_schools_guard_test set slug='attacker-route' where id=1;
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client school routing change unexpectedly passed the guard'; end if;

  foreach stmt in array array[
    'update pg_temp.stage1_users_guard_test set id = 2 where id = 1',
    'update pg_temp.stage1_users_guard_test set code = ''T-2'' where id = 1',
    'update pg_temp.stage1_users_guard_test set username = ''new-user'' where id = 1',
    'update pg_temp.stage1_users_guard_test set email = ''new@example.invalid'' where id = 1',
    'update pg_temp.stage1_users_guard_test set password = ''new-hash'' where id = 1',
    'update pg_temp.stage1_users_guard_test set remember_token = ''new-token'' where id = 1',
    'update pg_temp.stage1_users_guard_test set email_verified_at = now() where id = 1',
    'update pg_temp.stage1_users_guard_test set user_type = ''super_admin'' where id = 1',
    'update pg_temp.stage1_users_guard_test set school_id = null where id = 1',
    'update pg_temp.stage1_users_guard_test set auth_user_id = ''00000000-0000-4000-8000-000000000099'' where id = 1'
  ] loop
    denied := false;
    begin execute stmt; exception when raise_exception then denied := true; end;
    if not denied then raise exception 'client identity update unexpectedly passed: %', stmt; end if;
  end loop;
  update pg_temp.stage1_users_guard_test set display_name='Updated profile detail' where id=1;
  update pg_temp.stage1_schools_guard_test set name='Updated school display name' where id=1;

  -- A user may set a custom GUC in an RPC, so the signed-role text alone is
  -- insufficient. The effective database role must still be authenticated.
  perform set_config('request.jwt.claim.role', 'service_role', true);
  denied := false;
  begin
    insert into pg_temp.stage1_users_guard_test
      values (3, 'T-3', 'forged', 'forged@example.invalid', 'hash', null, null, 'admin', 1,
              '00000000-0000-4000-8000-000000000003', 'Forged');
  exception when raise_exception then denied := true;
  end;
  if not denied then raise exception 'forged service_role GUC bypassed the profile guard'; end if;
  perform set_config('request.jwt.claim.role', 'authenticated', true);

  denied := false;
  begin insert into pg_temp.stage1_authz_guard_test values (2, true);
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client role assignment insert unexpectedly passed the guard'; end if;
  denied := false;
  begin update pg_temp.stage1_authz_guard_test set allowed = false where id = 1;
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client permission assignment update unexpectedly passed the guard'; end if;
  denied := false;
  begin delete from pg_temp.stage1_authz_guard_test where id = 1;
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client permission assignment delete unexpectedly passed the guard'; end if;

  -- Unlinked subjects cannot impersonate any audit actor, including a NULL
  -- actor, even if a client forges the custom JWT role GUC.
  perform set_config('request.jwt.claim.role', 'service_role', true);
  denied := false;
  begin insert into pg_temp.stage1_audit_guard_test values (999999999, 1);
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'client forged role GUC to impersonate an audit actor'; end if;
  perform set_config('request.jwt.claim.role', 'authenticated', true);
end
$test$;

-- SQL-editor role simulation only: synthetic claim settings are not a signed
-- service JWT or a real PostgREST request. It checks the guard's non-NULL-sub
-- handling and effective database role; the actual authenticator -> service_role
-- API path still needs a server/API test with a real service credential.
reset role;
select set_config('request.jwt.claim.sub', '00000000-0000-4000-8000-000000000099', true);
select set_config('request.jwt.claim.role', 'service_role', true);
select set_config('request.jwt.claims', '{"sub":"00000000-0000-4000-8000-000000000099","role":"service_role"}', true);
set local role service_role;
do $test$
begin
  if auth.uid() is null or auth.role() <> 'service_role' or current_user <> 'service_role'
     or current_setting('role', true) <> 'service_role'
     or session_user not in ('postgres', 'supabase_admin') then
    raise exception 'unexpected simulated service context: session_user %, current_user %, role setting %',
      session_user, current_user, current_setting('role', true);
  end if;
  insert into pg_temp.stage1_users_guard_test
    values (4, 'T-4', 'server-user', 'server@example.invalid', 'hash', null, null, 'admin', 1,
            '00000000-0000-4000-8000-000000000099', 'Server-created');
  insert into pg_temp.stage1_authz_guard_test values (2, true);
  insert into pg_temp.stage1_schools_guard_test values (2, 'S2', 'server-school', 'trial', 'Server school');
  update pg_temp.stage1_users_guard_test set user_type='teacher' where id=4;
end
$test$;
reset role;

-- Direct database-owner/migration path: clear synthetic JWT settings and make
-- sure the SECURITY DEFINER audit trigger trusts the original owner identity,
-- not its own current_user=postgres value. Actorless system audit is valid.
select set_config('request.jwt.claim.sub', '', true);
select set_config('request.jwt.claim.role', '', true);
select set_config('request.jwt.claims', '', true);
do $owner_audit_context$
begin
  if session_user not in ('postgres', 'supabase_admin')
     or current_user not in ('postgres', 'supabase_admin')
     or coalesce(nullif(current_setting('role', true), 'none'), session_user) <> session_user
     or auth.uid() is not null or auth.role() is not null then
    raise exception 'unexpected direct-owner audit context: session_user %, current_user %, role %, JWT role %, subject %',
      session_user, current_user, current_setting('role', true), auth.role(), auth.uid();
  end if;
  insert into pg_temp.stage1_audit_guard_test values (null, null);
end
$owner_audit_context$;

-- Trusted background/system work may append an actorless audit row only when
-- the service credential has no linked profile; it may still carry an Auth
-- subject during the short provisioning interval. A linked subject must use
-- its own profile and no subject may impersonate a different one.
select set_config('request.jwt.claim.sub', '', true);
select set_config('request.jwt.claims', '{"role":"service_role"}', true);
set local role service_role;
insert into pg_temp.stage1_audit_guard_test values (null, null);
reset role;
select set_config('request.jwt.claim.sub', '00000000-0000-4000-8000-000000000099', true);
select set_config('request.jwt.claims', '{"sub":"00000000-0000-4000-8000-000000000099","role":"service_role"}', true);
set local role service_role;
do $test$
declare denied boolean := false;
begin
  insert into pg_temp.stage1_audit_guard_test values (null, null);
  begin insert into pg_temp.stage1_audit_guard_test values (999999999, 1);
  exception when raise_exception then denied := true; end;
  if not denied then raise exception 'unlinked service subject impersonated a stored audit actor'; end if;
end
$test$;
reset role;

-- These cases use existing legitimate records only. With the currently empty
-- project they emit a NOTICE and do not create users or schools as fixtures.
set local role authenticated;
do $audit_cases$
declare
  f record;
  denied boolean;
begin
  select * into f from pg_temp.stage1_audit_fixtures limit 1;
  if f.actor_a is null then
    raise notice 'SKIP own-school/cross-school audit cases: no linked school profile exists';
  else
    perform set_config('request.jwt.claim.sub', f.auth_a::text, true);
    perform set_config('request.jwt.claim.role', 'authenticated', true);
    perform set_config('request.jwt.claims', json_build_object('sub', f.auth_a::text, 'role', 'authenticated')::text, true);
    insert into pg_temp.stage1_audit_guard_test values (f.actor_a, f.school_a);

    if f.school_b is not null then
      denied := false;
      begin insert into pg_temp.stage1_audit_guard_test values (f.actor_a, f.school_b);
      exception when raise_exception then denied := true; end;
      if not denied then raise exception 'school actor could append audit row for a different school'; end if;
    else
      raise notice 'SKIP cross-school audit isolation: no second linked school exists';
    end if;

    if f.actor_b is not null then
      denied := false;
      begin insert into pg_temp.stage1_audit_guard_test values (f.actor_b, f.school_a);
      exception when raise_exception then denied := true; end;
      if not denied then raise exception 'school actor could impersonate another audit actor'; end if;
    else
      raise notice 'SKIP actor impersonation with real profiles: no second linked school profile exists';
    end if;
  end if;

  if f.platform_actor is not null and f.school_b is not null then
    perform set_config('request.jwt.claim.sub', f.platform_auth::text, true);
    perform set_config('request.jwt.claim.role', 'authenticated', true);
    perform set_config('request.jwt.claims', json_build_object('sub', f.platform_auth::text, 'role', 'authenticated')::text, true);
    insert into pg_temp.stage1_audit_guard_test values (f.platform_actor, f.school_b);
  else
    raise notice 'SKIP platform super-admin cross-school audit: linked super-admin and second school are required';
  end if;
end
$audit_cases$;

rollback;
