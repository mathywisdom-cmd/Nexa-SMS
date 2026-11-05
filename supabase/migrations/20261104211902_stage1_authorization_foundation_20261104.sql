begin;

do $constraint_preflight$
begin
  if exists (
    select 1 from pg_catalog.pg_constraint
     where conrelid = 'public.users'::regclass
       and conname = 'users_super_admin_is_platform_only'
  ) then
    raise exception 'Stage 1 stopped: public.users constraint users_super_admin_is_platform_only already exists; inspect it before retrying';
  end if;
end
$constraint_preflight$;

alter table public.users
  add constraint users_super_admin_is_platform_only
  check (user_type <> 'super_admin' or school_id is null);

do $helper_preflight$
declare
  spec record;
  helper_owner name;
  helper_definer boolean;
  helper_config text[];
begin
  for spec in
    select * from (values
      ('private.current_profile_id()'::regprocedure, 'current_profile_id'),
      ('private.current_school_id()'::regprocedure, 'current_school_id'),
      ('private.has_permission(text)'::regprocedure, 'has_permission'),
      ('private.check_audit_actor_school()'::regprocedure, 'check_audit_actor_school')
    ) as expected(function_oid, function_name)
  loop
    select r.rolname, p.prosecdef, p.proconfig
      into helper_owner, helper_definer, helper_config
      from pg_catalog.pg_proc p join pg_catalog.pg_roles r on r.oid=p.proowner
     where p.oid=spec.function_oid;
    if not found or helper_owner <> 'postgres' or not helper_definer
       or not ('search_path=""' = any(helper_config))
       or not ('row_security=off' = any(helper_config)) then
      raise exception 'private helper % has unexpected owner/SECURITY DEFINER/search_path/row_security configuration', spec.function_name;
    end if;
    if spec.function_name = 'check_audit_actor_school' then
      if has_function_privilege('authenticated', spec.function_oid, 'EXECUTE')
         or has_function_privilege('anon', spec.function_oid, 'EXECUTE')
         or has_function_privilege('service_role', spec.function_oid, 'EXECUTE') then
        raise exception 'audit trigger function must remain trigger-only, without client/service execute grants';
      end if;
    elsif not has_function_privilege('authenticated', spec.function_oid, 'EXECUTE')
       or has_function_privilege('anon', spec.function_oid, 'EXECUTE')
       or has_function_privilege('service_role', spec.function_oid, 'EXECUTE') then
      raise exception 'private helper % has unexpected client/server execution grants', spec.function_name;
    end if;
  end loop;
end
$helper_preflight$;

create or replace function private.guard_profile_authority_fields()
returns trigger
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  jwt_role text;
  role_setting text;
  effective_role text;
  trusted_server boolean;
begin
  jwt_role := coalesce(
    nullif(current_setting('request.jwt.claim.role', true), ''),
    nullif(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'role', '')
  );
  role_setting := nullif(current_setting('role', true), 'none');
  effective_role := coalesce(role_setting, current_user);
  trusted_server :=
    (jwt_role = 'service_role'
      and effective_role = 'service_role'
      and current_user = 'service_role'
      and session_user in ('authenticator', 'service_role', 'postgres', 'supabase_admin'))
    or (jwt_role is null and auth.uid() is null
      and session_user in ('postgres', 'supabase_admin')
      and current_user in ('postgres', 'supabase_admin')
      and effective_role = session_user);

  if not trusted_server then
    if tg_op = 'DELETE' then
      raise exception 'profiles may be deleted only by trusted account lifecycle operations';
    end if;
    if tg_op = 'INSERT' then
      raise exception 'profiles and authentication identity are managed by trusted provisioning';
    end if;
    if new.id is distinct from old.id
       or new.code is distinct from old.code
       or new.username is distinct from old.username
       or new.email is distinct from old.email
       or new.password is distinct from old.password
       or new.remember_token is distinct from old.remember_token
       or new.email_verified_at is distinct from old.email_verified_at
       or new.user_type is distinct from old.user_type
       or new.school_id is distinct from old.school_id
       or new.auth_user_id is distinct from old.auth_user_id then
      raise exception 'authentication, role, and school identity fields are managed by trusted provisioning';
    end if;
  end if;
  if tg_op = 'DELETE' then return old; end if;
  return new;
end
$function$;
alter function private.guard_profile_authority_fields() owner to postgres;
revoke all on function private.guard_profile_authority_fields() from public, anon, authenticated;

create or replace function private.guard_school_authority_fields()
returns trigger
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  jwt_role text;
  role_setting text;
  effective_role text;
  trusted_server boolean;
begin
  jwt_role := coalesce(
    nullif(current_setting('request.jwt.claim.role', true), ''),
    nullif(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'role', '')
  );
  role_setting := nullif(current_setting('role', true), 'none');
  effective_role := coalesce(role_setting, current_user);
  trusted_server :=
    (jwt_role = 'service_role'
      and effective_role = 'service_role'
      and current_user = 'service_role'
      and session_user in ('authenticator', 'service_role', 'postgres', 'supabase_admin'))
    or (jwt_role is null and auth.uid() is null
      and session_user in ('postgres', 'supabase_admin')
      and current_user in ('postgres', 'supabase_admin')
      and effective_role = session_user);

  if not trusted_server then
    if tg_op = 'DELETE' then
      raise exception 'schools may be deleted only by trusted lifecycle operations';
    end if;
    if tg_op = 'INSERT' then
      raise exception 'schools are created by trusted provisioning';
    end if;
    if new.id is distinct from old.id
       or new.code is distinct from old.code
       or new.slug is distinct from old.slug
       or new.status is distinct from old.status then
      raise exception 'school identity, routing, and activation fields are managed by trusted operations';
    end if;
  end if;
  if tg_op = 'DELETE' then return old; end if;
  return new;
end
$function$;
alter function private.guard_school_authority_fields() owner to postgres;
revoke all on function private.guard_school_authority_fields() from public, anon, authenticated;

create or replace function private.guard_authorization_catalog_writes()
returns trigger
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  jwt_role text;
  role_setting text;
  effective_role text;
  trusted_server boolean;
begin
  jwt_role := coalesce(
    nullif(current_setting('request.jwt.claim.role', true), ''),
    nullif(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'role', '')
  );
  role_setting := nullif(current_setting('role', true), 'none');
  effective_role := coalesce(role_setting, current_user);
  trusted_server :=
    (jwt_role = 'service_role'
      and effective_role = 'service_role'
      and current_user = 'service_role'
      and session_user in ('authenticator', 'service_role', 'postgres', 'supabase_admin'))
    or (jwt_role is null and auth.uid() is null
      and session_user in ('postgres', 'supabase_admin')
      and current_user in ('postgres', 'supabase_admin')
      and effective_role = session_user);

  if not trusted_server then
    raise exception 'authorization catalogs and permission assignments are managed by trusted server operations';
  end if;
  if tg_op = 'DELETE' then
    return old;
  end if;
  return new;
end
$function$;
alter function private.guard_authorization_catalog_writes() owner to postgres;
revoke all on function private.guard_authorization_catalog_writes() from public, anon, authenticated;

do $triggers$
declare
  spec record;
  found_function oid;
  found_type smallint;
  found_enabled "char";
begin
  for spec in
    select * from (values
      ('public.users'::regclass, 'users_guard_authority_fields', 'private.guard_profile_authority_fields()'::regprocedure::oid, 31::smallint,
       'create trigger users_guard_authority_fields before insert or update or delete on public.users for each row execute function private.guard_profile_authority_fields()'),
      ('public.schools'::regclass, 'schools_guard_authority_fields', 'private.guard_school_authority_fields()'::regprocedure::oid, 31::smallint,
       'create trigger schools_guard_authority_fields before insert or update or delete on public.schools for each row execute function private.guard_school_authority_fields()'),
      ('public.user_permissions'::regclass, 'user_permissions_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint,
       'create trigger user_permissions_guard_client_writes before insert or update or delete on public.user_permissions for each row execute function private.guard_authorization_catalog_writes()'),
      ('public.role_permissions'::regclass, 'role_permissions_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint,
       'create trigger role_permissions_guard_client_writes before insert or update or delete on public.role_permissions for each row execute function private.guard_authorization_catalog_writes()'),
      ('public.roles'::regclass, 'roles_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint,
       'create trigger roles_guard_client_writes before insert or update or delete on public.roles for each row execute function private.guard_authorization_catalog_writes()'),
      ('public.permissions'::regclass, 'permissions_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint,
       'create trigger permissions_guard_client_writes before insert or update or delete on public.permissions for each row execute function private.guard_authorization_catalog_writes()'),
      ('public.user_types'::regclass, 'user_types_guard_client_writes', 'private.guard_authorization_catalog_writes()'::regprocedure::oid, 31::smallint,
       'create trigger user_types_guard_client_writes before insert or update or delete on public.user_types for each row execute function private.guard_authorization_catalog_writes()')
    ) as expected(table_oid, trigger_name, function_oid, trigger_type, create_sql)
  loop
    select t.tgfoid, t.tgtype, t.tgenabled
      into found_function, found_type, found_enabled
      from pg_catalog.pg_trigger t
     where t.tgrelid = spec.table_oid
       and t.tgname = spec.trigger_name
       and not t.tgisinternal;

    if not found then
      execute spec.create_sql;
    elsif found_function <> spec.function_oid
       or found_type <> spec.trigger_type
       or found_enabled <> 'O' then
      raise exception 'refusing to overwrite or accept mismatched trigger %.%', spec.table_oid, spec.trigger_name;
    end if;
  end loop;
end
$triggers$;

do $audit_trigger_check$
declare
  existing_function oid;
  existing_type smallint;
  existing_enabled "char";
begin
  select t.tgfoid, t.tgtype, t.tgenabled
    into existing_function, existing_type, existing_enabled
    from pg_catalog.pg_trigger t
   where t.tgrelid = 'public.audit_logs'::regclass
     and t.tgname = 'audit_logs_actor_school_guard'
     and not t.tgisinternal;

  if not found
     or existing_function <> 'private.check_audit_actor_school()'::regprocedure::oid
     or existing_type <> 7
     or existing_enabled <> 'O' then
    raise exception 'expected enabled BEFORE INSERT row trigger audit_logs_actor_school_guard on public.audit_logs was not found exactly';
  end if;
end
$audit_trigger_check$;

create or replace function private.check_audit_actor_school()
returns trigger
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  actor_school integer;
  actor_type text;
  caller_profile integer;
  jwt_role text;
  role_setting text;
  trusted_server boolean;
begin
  jwt_role := coalesce(
    nullif(current_setting('request.jwt.claim.role', true), ''),
    nullif(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'role', '')
  );
  role_setting := nullif(current_setting('role', true), 'none');
  trusted_server :=
    (jwt_role = 'service_role' and (
       (session_user = 'authenticator' and role_setting = 'service_role')
       or (session_user = 'service_role' and coalesce(role_setting, 'service_role') = 'service_role')
       or (session_user in ('postgres', 'supabase_admin') and role_setting = 'service_role')
     ))
    or (jwt_role is null and auth.uid() is null
        and session_user in ('postgres', 'supabase_admin')
        and coalesce(role_setting, session_user) = session_user);

  if not trusted_server then
    caller_profile := private.current_profile_id();
    if caller_profile is null or new.actor_id is distinct from caller_profile then
      raise exception 'audit actor must match the authenticated NexaSMS profile';
    end if;
  elsif auth.uid() is not null then
    caller_profile := private.current_profile_id();
    if (caller_profile is null and new.actor_id is not null)
       or (caller_profile is not null and new.actor_id is distinct from caller_profile) then
      raise exception 'trusted audit actor must match the linked JWT subject';
    end if;
  elsif new.actor_id is not null then
    raise exception 'system audit events without a user subject must have a NULL actor';
  end if;

  if new.actor_id is null then
    if not trusted_server then
      raise exception 'authenticated audit events require an actor';
    end if;
    return new;
  end if;

  select u.school_id, u.user_type
    into actor_school, actor_type
    from public.users u
   where u.id = new.actor_id;
  if not found then
    raise exception 'audit actor does not exist';
  end if;

  if actor_type = 'super_admin' then
    return new;
  end if;

  if actor_school is null or new.school_id is distinct from actor_school then
    raise exception 'audit actor school does not match audit school';
  end if;
  return new;
end
$function$;
alter function private.check_audit_actor_school() owner to postgres;
revoke all on function private.check_audit_actor_school() from public, anon, authenticated;

revoke all on table
  public.users,
  public.schools,
  public.user_permissions,
  public.role_permissions,
  public.roles,
  public.permissions,
  public.user_types
from public, anon, authenticated;

commit;