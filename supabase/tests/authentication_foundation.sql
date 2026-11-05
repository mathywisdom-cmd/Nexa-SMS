-- Read-only/transaction-safe checks for the Stage 1 auth foundation.
-- This script expects no authenticated JWT claims and creates no records.
do $test$
declare
  password_nullable text;
begin
  select c.is_nullable into password_nullable
  from information_schema.columns c
  where c.table_schema = 'public' and c.table_name = 'users' and c.column_name = 'password';
  if password_nullable is distinct from 'YES' then
    raise exception 'public.users.password must be nullable for Supabase Auth profiles';
  end if;

  if public.get_my_auth_context() is not null then
    raise exception 'get_my_auth_context must return NULL without an Auth subject';
  end if;

  if not has_function_privilege('authenticated', 'public.get_my_auth_context()', 'EXECUTE')
     or has_function_privilege('anon', 'public.get_my_auth_context()', 'EXECUTE')
     or has_function_privilege('service_role', 'public.get_my_auth_context()', 'EXECUTE') then
    raise exception 'auth context RPC execution grants are not scoped as intended';
  end if;

  if not has_schema_privilege('service_role', 'private', 'USAGE')
     or not has_function_privilege('service_role', 'private.profile_has_permission(integer,text)', 'EXECUTE')
     or has_function_privilege('anon', 'private.profile_has_permission(integer,text)', 'EXECUTE')
     or has_function_privilege('authenticated', 'private.profile_has_permission(integer,text)', 'EXECUTE') then
    raise exception 'internal permission helper privileges are not scoped as intended';
  end if;

  if has_function_privilege('anon', 'private.provisioning_payload_has_credentials(jsonb)', 'EXECUTE')
     or has_function_privilege('authenticated', 'private.provisioning_payload_has_credentials(jsonb)', 'EXECUTE')
     or not has_function_privilege('service_role', 'private.provisioning_payload_has_credentials(jsonb)', 'EXECUTE') then
    raise exception 'recursive credential guard privileges are not scoped as intended';
  end if;

  if has_table_privilege('authenticated', 'public.users', 'SELECT')
     or has_table_privilege('anon', 'public.users', 'SELECT')
     or has_table_privilege('authenticated', 'public.schools', 'SELECT')
     or has_table_privilege('anon', 'public.schools', 'SELECT') then
    raise exception 'users or schools table unexpectedly exposes direct reads';
  end if;

  if not has_schema_privilege('service_role', 'auth', 'USAGE')
     or not has_table_privilege('service_role', 'auth.users', 'SELECT')
     or not has_table_privilege('service_role', 'public.users', 'SELECT')
     or not has_table_privilege('service_role', 'public.users', 'INSERT')
     or not has_table_privilege('service_role', 'public.schools', 'SELECT')
     or not has_table_privilege('service_role', 'public.schools', 'INSERT')
     or not has_table_privilege('service_role', 'public.roles', 'SELECT')
     or not has_table_privilege('service_role', 'public.states', 'SELECT')
     or not has_table_privilege('service_role', 'public.lgas', 'SELECT')
     or not has_table_privilege('service_role', 'public.nationalities', 'SELECT')
     or not has_table_privilege('service_role', 'public.staff_records', 'INSERT')
     or not has_table_privilege('service_role', 'public.audit_logs', 'SELECT')
     or not has_table_privilege('service_role', 'public.audit_logs', 'INSERT') then
    raise exception 'trusted provisioning table privileges are incomplete';
  end if;

  if not has_sequence_privilege('service_role', pg_catalog.pg_get_serial_sequence('public.users', 'id'), 'USAGE')
     or not has_sequence_privilege('service_role', pg_catalog.pg_get_serial_sequence('public.schools', 'id'), 'USAGE')
     or not has_sequence_privilege('service_role', pg_catalog.pg_get_serial_sequence('public.staff_records', 'id'), 'USAGE')
     or not has_sequence_privilege('service_role', pg_catalog.pg_get_serial_sequence('public.audit_logs', 'id'), 'USAGE') then
    raise exception 'trusted provisioning sequence privileges are incomplete';
  end if;

  if has_table_privilege('service_role', 'public.users', 'UPDATE') then raise exception 'service_role unexpectedly has UPDATE on public.users'; end if;
  if has_table_privilege('service_role', 'public.users', 'DELETE') then raise exception 'service_role unexpectedly has DELETE on public.users'; end if;
  if has_table_privilege('service_role', 'public.schools', 'UPDATE') then raise exception 'service_role unexpectedly has UPDATE on public.schools'; end if;
  if has_table_privilege('service_role', 'public.schools', 'DELETE') then raise exception 'service_role unexpectedly has DELETE on public.schools'; end if;
  if has_table_privilege('service_role', 'public.staff_records', 'SELECT') then raise exception 'service_role unexpectedly has SELECT on public.staff_records'; end if;
  if has_table_privilege('service_role', 'public.staff_records', 'UPDATE') then raise exception 'service_role unexpectedly has UPDATE on public.staff_records'; end if;
  if has_table_privilege('service_role', 'public.staff_records', 'DELETE') then raise exception 'service_role unexpectedly has DELETE on public.staff_records'; end if;
  if has_table_privilege('service_role', 'public.roles', 'INSERT') then raise exception 'service_role unexpectedly has INSERT on public.roles'; end if;
  if has_table_privilege('service_role', 'public.roles', 'UPDATE') then raise exception 'service_role unexpectedly has UPDATE on public.roles'; end if;
  if has_table_privilege('service_role', 'public.roles', 'DELETE') then raise exception 'service_role unexpectedly has DELETE on public.roles'; end if;

  if has_table_privilege('anon', 'public.users', 'INSERT')
     or has_table_privilege('authenticated', 'public.users', 'INSERT')
     or has_table_privilege('anon', 'public.schools', 'INSERT')
     or has_table_privilege('authenticated', 'public.schools', 'INSERT')
     or has_table_privilege('anon', 'public.staff_records', 'INSERT')
     or has_table_privilege('authenticated', 'public.staff_records', 'INSERT')
     or has_table_privilege('anon', 'public.roles', 'INSERT')
     or has_table_privilege('authenticated', 'public.roles', 'INSERT') then
    raise exception 'browser role unexpectedly has a direct provisioning or role write privilege';
  end if;

  if has_function_privilege('anon', 'public.provision_platform_owner_profile(uuid,uuid,text,uuid)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.provision_platform_owner_profile(uuid,uuid,text,uuid)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.provision_platform_owner_profile(uuid,uuid,text,uuid)', 'EXECUTE') then
    raise exception 'platform-owner provisioning RPC grants are not service-role-only';
  end if;

  if has_function_privilege('anon', 'public.provision_school_and_first_admin(uuid,uuid,uuid,text,text,text,text,text,text,text,text,text,text,uuid)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.provision_school_and_first_admin(uuid,uuid,uuid,text,text,text,text,text,text,text,text,text,text,uuid)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.provision_school_and_first_admin(uuid,uuid,uuid,text,text,text,text,text,text,text,text,text,text,uuid)', 'EXECUTE') then
    raise exception 'school provisioning RPC grants are not service-role-only';
  end if;

  if has_function_privilege('anon', 'public.provision_school_staff_profile(uuid,uuid,uuid,text,text,text,text,text,text,integer,integer,integer,text,uuid)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.provision_school_staff_profile(uuid,uuid,uuid,text,text,text,text,text,text,integer,integer,integer,text,uuid)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.provision_school_staff_profile(uuid,uuid,uuid,text,text,text,text,text,text,integer,integer,integer,text,uuid)', 'EXECUTE') then
    raise exception 'school user provisioning RPC grants are not service-role-only';
  end if;

  if has_function_privilege('anon', 'public.consume_username_login_attempt(text,text)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.consume_username_login_attempt(text,text)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.consume_username_login_attempt(text,text)', 'EXECUTE') then
    raise exception 'username rate-limit RPC grants are not service-role-only';
  end if;

  if has_table_privilege('anon', 'private.username_login_attempts', 'SELECT')
     or has_table_privilege('authenticated', 'private.username_login_attempts', 'SELECT')
     or has_table_privilege('service_role', 'private.username_login_attempts', 'SELECT') then
    raise exception 'private username-attempt data is directly readable';
  end if;

  if (select count(*) from pg_catalog.pg_index i
      join pg_catalog.pg_class t on t.oid = i.indrelid
      join pg_catalog.pg_namespace n on n.oid = t.relnamespace
      join pg_catalog.pg_class idx on idx.oid = i.indexrelid
      where n.nspname = 'public' and t.relname = 'users'
        and idx.relname = 'users_single_platform_owner' and i.indisunique and i.indisvalid) <> 1 then
    raise exception 'single platform owner unique index is missing or invalid';
  end if;

  if has_table_privilege('anon', 'private.provisioning_requests', 'SELECT')
     or has_table_privilege('authenticated', 'private.provisioning_requests', 'SELECT')
     or not has_table_privilege('service_role', 'private.provisioning_requests', 'SELECT')
     or not has_table_privilege('service_role', 'private.provisioning_requests', 'INSERT')
     or not has_table_privilege('service_role', 'private.provisioning_requests', 'UPDATE')
     or has_table_privilege('service_role', 'private.provisioning_requests', 'DELETE')
     or not exists (
       select 1 from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid=c.relnamespace
       where n.nspname='private' and c.relname='provisioning_requests'
         and c.relrowsecurity and c.relforcerowsecurity
     ) then
    raise exception 'provisioning ledger is not private and FORCE RLS protected';
  end if;

  if has_function_privilege('anon', 'public.reserve_provisioning_request(uuid,text,uuid,jsonb,text)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.reserve_provisioning_request(uuid,text,uuid,jsonb,text)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.reserve_provisioning_request(uuid,text,uuid,jsonb,text)', 'EXECUTE')
      or has_function_privilege('anon', 'public.record_provisioning_identity(uuid,uuid,uuid)', 'EXECUTE')
      or has_function_privilege('authenticated', 'public.record_provisioning_identity(uuid,uuid,uuid)', 'EXECUTE')
      or not has_function_privilege('service_role', 'public.record_provisioning_identity(uuid,uuid,uuid)', 'EXECUTE')
     or has_function_privilege('anon', 'public.lookup_provisioning_identity(uuid)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.lookup_provisioning_identity(uuid)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.lookup_provisioning_identity(uuid)', 'EXECUTE')
      or has_function_privilege('anon', 'public.transition_provisioning_request(uuid,text,text,uuid,uuid)', 'EXECUTE')
      or has_function_privilege('authenticated', 'public.transition_provisioning_request(uuid,text,text,uuid,uuid)', 'EXECUTE')
      or not has_function_privilege('service_role', 'public.transition_provisioning_request(uuid,text,text,uuid,uuid)', 'EXECUTE')
     or has_function_privilege('anon', 'public.get_provisioning_request(uuid)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.get_provisioning_request(uuid)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.get_provisioning_request(uuid)', 'EXECUTE')
     or has_function_privilege('anon', 'public.claim_provisioning_request(uuid,uuid)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.claim_provisioning_request(uuid,uuid)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.claim_provisioning_request(uuid,uuid)', 'EXECUTE')
      or has_function_privilege('anon', 'public.renew_provisioning_lease(uuid,uuid)', 'EXECUTE')
      or has_function_privilege('authenticated', 'public.renew_provisioning_lease(uuid,uuid)', 'EXECUTE')
      or not has_function_privilege('service_role', 'public.renew_provisioning_lease(uuid,uuid)', 'EXECUTE')
     or has_function_privilege('anon', 'public.begin_provisioning_cancellation(uuid,uuid)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.begin_provisioning_cancellation(uuid,uuid)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.begin_provisioning_cancellation(uuid,uuid)', 'EXECUTE')
     or has_function_privilege('anon', 'public.finish_provisioning_cancellation(uuid,uuid,boolean)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.finish_provisioning_cancellation(uuid,uuid,boolean)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.finish_provisioning_cancellation(uuid,uuid,boolean)', 'EXECUTE') then
    raise exception 'provisioning ledger RPC privileges are not server-only';
  end if;

  if has_function_privilege('anon', 'public.resume_owner_bootstrap_provisioning(uuid)', 'EXECUTE')
     or has_function_privilege('authenticated', 'public.resume_owner_bootstrap_provisioning(uuid)', 'EXECUTE')
     or not has_function_privilege('service_role', 'public.resume_owner_bootstrap_provisioning(uuid)', 'EXECUTE') then
    raise exception 'bootstrap recovery RPC privileges are not server-only';
  end if;

  if exists (
    select 1
    from pg_catalog.pg_proc p
    join pg_catalog.pg_namespace n on n.oid = p.pronamespace
    where (n.nspname = 'public' and p.proname in (
        'reserve_provisioning_request', 'record_provisioning_identity', 'lookup_provisioning_identity',
        'transition_provisioning_request', 'get_provisioning_request', 'claim_provisioning_request',
        'renew_provisioning_lease', 'begin_provisioning_cancellation', 'finish_provisioning_cancellation',
        'resume_owner_bootstrap_provisioning', 'get_my_auth_context', 'provision_platform_owner_profile',
        'provision_school_and_first_admin', 'provision_school_staff_profile', 'consume_username_login_attempt'
      ) or (n.nspname = 'private' and p.proname in (
        'check_audit_actor_school', 'current_profile_id', 'current_school_id', 'has_permission',
        'profile_has_permission', 'provisioning_payload_has_credentials'
      )))
      and (
        p.proowner <> 'postgres'::regrole
        or not exists (
          select 1 from unnest(coalesce(p.proconfig, array[]::text[])) as settings(setting)
          where settings.setting like 'search_path=%'
        )
      )
  ) then
    raise exception 'authorization/provisioning functions must be postgres-owned with a fixed search_path';
  end if;

  if not (select p.prosecdef from pg_catalog.pg_proc p where p.oid = 'private.check_audit_actor_school()'::regprocedure)
     or not exists (
       select 1 from pg_catalog.pg_trigger t
       where t.tgrelid = 'public.audit_logs'::regclass
         and t.tgname = 'audit_logs_actor_school_guard'
         and t.tgfoid = 'private.check_audit_actor_school()'::regprocedure
         and t.tgenabled = 'O' and t.tgtype = 7
     ) then
    raise exception 'audit guard must be the enabled BEFORE INSERT row trigger bound to the expected SECURITY DEFINER function';
  end if;

  if (select count(*) from pg_catalog.pg_constraint c
      where c.conrelid = 'public.users'::regclass and c.conname = 'users_super_admin_is_platform_only'
        and c.contype = 'c' and c.convalidated) <> 1 then
    raise exception 'platform owner school-null invariant is missing or unvalidated';
  end if;
end
$test$;
