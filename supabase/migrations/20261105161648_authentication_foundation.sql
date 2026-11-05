-- REVIEW STATUS: REJECTED proposal; do not apply to any hosted project.
-- Nexa SMS Stage 1 authentication foundation.
-- Supabase Auth owns credentials; public.users.password remains only as a
-- nullable legacy compatibility column and is never read by the SPA.

begin;

alter table public.users alter column password drop not null;

-- The platform must have at most one owner, even during concurrent bootstrap.
create unique index users_single_platform_owner
  on public.users ((user_type)) where user_type = 'super_admin';

create or replace function private.profile_has_permission(p_profile_id integer, p_permission text)
returns boolean
language sql
stable
security definer
set search_path = ''
set row_security = off
as $function$
  select coalesce(up.allowed, rp.permission_id is not null)
  from public.users u
  join public.roles r on r.name = u.user_type
  join public.permissions p on p.name = p_permission
  left join public.role_permissions rp on rp.role_id = r.id and rp.permission_id = p.id
  left join public.user_permissions up on up.user_id = u.id and up.permission_id = p.id
  where u.id = p_profile_id
    and ((u.user_type = 'super_admin') = (p.name like 'platform.%'))
  limit 1
$function$;
alter function private.profile_has_permission(integer, text) owner to postgres;
revoke all on function private.profile_has_permission(integer, text) from public, anon, authenticated, service_role;
grant usage on schema private to service_role;
grant execute on function private.profile_has_permission(integer, text) to service_role;

create or replace function private.provisioning_payload_has_credentials(p_value jsonb)
returns boolean
language plpgsql
immutable
security invoker
set search_path = ''
as $function$
declare
  item record;
  normalized_key text;
begin
  if jsonb_typeof(p_value) = 'object' then
    for item in select e.key, e.value from pg_catalog.jsonb_each(p_value) e loop
      normalized_key := pg_catalog.regexp_replace(pg_catalog.lower(item.key), '[^a-z0-9]', '', 'g');
      if normalized_key = any (array[
        'password', 'passwordconfirmation', 'accesstoken', 'refreshtoken',
        'servicerole', 'servicerolekey', 'bootstraptoken', 'provisioningoperatortoken',
        'secret', 'credentials'
      ]) or private.provisioning_payload_has_credentials(item.value) then
        return true;
      end if;
    end loop;
  elsif jsonb_typeof(p_value) = 'array' then
    for item in select a.value from pg_catalog.jsonb_array_elements(p_value) a loop
      if private.provisioning_payload_has_credentials(item.value) then return true; end if;
    end loop;
  end if;
  return false;
end
$function$;
alter function private.provisioning_payload_has_credentials(jsonb) owner to postgres;
revoke all on function private.provisioning_payload_has_credentials(jsonb) from public, anon, authenticated, service_role;
grant execute on function private.provisioning_payload_has_credentials(jsonb) to service_role;

create table private.provisioning_requests (
  request_id uuid primary key,
  operation text not null check (operation in ('owner_bootstrap', 'school_create', 'school_user_create')),
  actor_auth_user_id uuid null,
  payload jsonb not null,
  constraint provisioning_requests_payload_no_credentials check (not private.provisioning_payload_has_credentials(payload)),
  payload_hash text not null check (payload_hash ~ '^[a-f0-9]{64}$'),
  state text not null default 'pending' check (state in ('pending', 'identity_created', 'profile_created', 'completed', 'failed_recoverable', 'failed_terminal', 'cancelling')),
  auth_user_id uuid null,
  profile_id integer null,
  school_id integer null,
  result jsonb null,
  error_code text null check (error_code is null or error_code in ('auth_invite_failed', 'profile_write_failed', 'compensation_failed', 'request_conflict', 'owner_already_exists', 'cancelled', 'recovery_required')),
  attempt_count integer not null default 0 check (attempt_count >= 0),
  last_attempt_at timestamptz null,
  recovered_by uuid null,
  recovered_at timestamptz null,
  recovery_channel text null check (recovery_channel is null or recovery_channel in ('platform_owner', 'bootstrap_operator')),
  cancelled_by uuid null,
  cancelled_at timestamptz null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  lease_token uuid null,
  lease_expires_at timestamptz null,
  constraint provisioning_requests_lease_pair check ((lease_token is null) = (lease_expires_at is null))
);
alter table private.provisioning_requests enable row level security;
alter table private.provisioning_requests force row level security;
revoke all on private.provisioning_requests from public, anon, authenticated, service_role;
-- The profile/school provisioning RPCs are SECURITY INVOKER and run as the
-- trusted service_role. Grant only the ledger DML they need; browser roles
-- retain no access and service_role cannot delete ledger history.
grant select, insert, update on private.provisioning_requests to service_role;
create index provisioning_requests_state_updated_idx on private.provisioning_requests(state, updated_at);
create index provisioning_requests_actor_created_idx on private.provisioning_requests(actor_auth_user_id, created_at desc);

-- The profile/school RPCs below are SECURITY INVOKER by design. Add the
-- base-table privileges those RPCs require to the project's existing
-- service_role grants; do not rewrite that role's pre-existing privileges.
-- Browser roles remain revoked and no RLS policies are added here.
-- SECURITY INVOKER runs with service_role's bypass-RLS capability, so its key
-- must remain server-only and every write path must retain actor/tenant checks.
grant usage on schema public, auth to service_role;
grant select on auth.users to service_role;
grant select, insert on public.users, public.schools to service_role;
grant select on public.roles, public.states, public.lgas, public.nationalities to service_role;
grant select, insert on public.audit_logs to service_role;
grant insert on public.staff_records to service_role;

-- Identity columns used by these writes need only their own backing sequences.
do $provisioning_sequence_grants$
declare
  table_name text;
  sequence_name text;
begin
  foreach table_name in array array[
    'public.users', 'public.schools', 'public.audit_logs', 'public.staff_records'
  ] loop
    sequence_name := pg_catalog.pg_get_serial_sequence(table_name, 'id');
    if sequence_name is not null then
      execute pg_catalog.format('grant usage, select on sequence %s to service_role', sequence_name);
    end if;
  end loop;
end
$provisioning_sequence_grants$;

create or replace function public.reserve_provisioning_request(
  p_request_id uuid,
  p_operation text,
  p_actor_auth_user_id uuid,
  p_payload jsonb,
  p_payload_hash text
) returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
  actor_profile_id integer;
  actor_school_id integer;
begin
  if p_request_id is null or p_payload is null or p_payload_hash !~ '^[a-f0-9]{64}$'
     or p_operation not in ('owner_bootstrap', 'school_create', 'school_user_create') then
    raise exception 'invalid provisioning request';
  end if;
  if (p_operation = 'owner_bootstrap') <> (p_actor_auth_user_id is null) then
    raise exception 'invalid provisioning actor';
  end if;
  if p_operation = 'school_create' then
    select u.id into actor_profile_id from public.users u
     where u.auth_user_id = p_actor_auth_user_id and u.user_type = 'super_admin' and u.school_id is null;
    if actor_profile_id is null or private.profile_has_permission(actor_profile_id, 'platform.schools.manage') is not true then
      raise exception 'provisioning actor is not authorized for school creation';
    end if;
  elsif p_operation = 'school_user_create' then
    select u.id, u.school_id into actor_profile_id, actor_school_id from public.users u
     where u.auth_user_id = p_actor_auth_user_id and u.user_type = 'admin' and u.school_id is not null;
    if actor_profile_id is null or private.profile_has_permission(actor_profile_id, 'school.manage') is not true
       or not exists (select 1 from public.schools s where s.id = actor_school_id and s.status in ('active', 'trial')) then
      raise exception 'provisioning actor is not authorized for school user creation';
    end if;
  end if;

  perform pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(p_request_id::text, 0));
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if found then
    if request_row.operation <> p_operation or request_row.actor_auth_user_id is distinct from p_actor_auth_user_id
       or request_row.payload_hash <> p_payload_hash
       or (request_row.state not in ('completed', 'failed_terminal') and request_row.payload <> p_payload) then
      raise exception 'idempotency key conflicts with a different provisioning request';
    end if;
    if request_row.state = 'failed_recoverable' then
      -- If the Auth identity was already recorded before the recoverable
      -- failure, resume at identity_created so the profile RPC's precondition
      -- remains true. Only requests with no linked identity restart at pending.
      update private.provisioning_requests set
        state = case when auth_user_id is null then 'pending' else 'identity_created' end,
        error_code = null,
        lease_token = null, lease_expires_at = null, updated_at = now()
       where request_id = p_request_id returning * into request_row;
    end if;
    update private.provisioning_requests set attempt_count = attempt_count + 1, last_attempt_at = now(), updated_at = now()
     where request_id = p_request_id returning * into request_row;
  else
    insert into private.provisioning_requests(request_id, operation, actor_auth_user_id, payload, payload_hash, attempt_count, last_attempt_at)
    values (p_request_id, p_operation, p_actor_auth_user_id, p_payload, p_payload_hash, 1, now())
    returning * into request_row;
  end if;
  return jsonb_build_object('request_id', request_row.request_id, 'state', request_row.state,
    'auth_user_id', request_row.auth_user_id, 'profile_id', request_row.profile_id,
    'school_id', request_row.school_id, 'result', request_row.result);
end
$function$;
alter function public.reserve_provisioning_request(uuid, text, uuid, jsonb, text) owner to postgres;
revoke all on function public.reserve_provisioning_request(uuid, text, uuid, jsonb, text) from public, anon, authenticated;
grant execute on function public.reserve_provisioning_request(uuid, text, uuid, jsonb, text) to service_role;

create or replace function public.record_provisioning_identity(p_request_id uuid, p_auth_user_id uuid, p_lease_token uuid)
returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
  actual_email text;
  requested_email text;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found then raise exception 'provisioning request not found'; end if;
  if request_row.state not in ('pending', 'identity_created')
     or request_row.lease_token is distinct from p_lease_token
     or request_row.lease_expires_at is null
     or request_row.lease_expires_at <= clock_timestamp() then
    raise exception 'provisioning worker does not own a live lease';
  end if;
  select lower(au.email) into actual_email from auth.users au
   where au.id = p_auth_user_id
     and au.raw_user_meta_data->>'nexasms_provisioning_request_id' = p_request_id::text;
  requested_email := lower(case when request_row.operation = 'school_create'
    then request_row.payload->>'admin_email' else request_row.payload->>'email' end);
  if actual_email is null or requested_email is null or actual_email <> requested_email then
    raise exception 'Auth identity does not match the reserved request';
  end if;
  if request_row.auth_user_id is not null and request_row.auth_user_id <> p_auth_user_id then
    raise exception 'request is already linked to another Auth identity';
  end if;
  if request_row.state in ('profile_created', 'completed') then
    return jsonb_build_object('request_id', request_row.request_id, 'state', request_row.state,
      'auth_user_id', request_row.auth_user_id, 'profile_id', request_row.profile_id,
      'school_id', request_row.school_id, 'result', request_row.result);
  end if;
  if request_row.state not in ('pending', 'failed_recoverable', 'identity_created') then
    raise exception 'provisioning request cannot accept an Auth identity in its current state';
  end if;
  update private.provisioning_requests set auth_user_id = p_auth_user_id, state = 'identity_created',
    error_code = null, updated_at = now() where request_id = p_request_id returning * into request_row;
  return jsonb_build_object('request_id', request_row.request_id, 'state', request_row.state,
    'auth_user_id', request_row.auth_user_id);
end
$function$;
alter function public.record_provisioning_identity(uuid, uuid, uuid) owner to postgres;
revoke all on function public.record_provisioning_identity(uuid, uuid, uuid) from public, anon, authenticated;
grant execute on function public.record_provisioning_identity(uuid, uuid, uuid) to service_role;

-- Resolve an invite whose HTTP response was lost without scanning/publishing
-- the Auth user directory. Return only the identity bound to this request, or
-- a boolean indicating that the requested email is occupied by another user.
create or replace function public.lookup_provisioning_identity(p_request_id uuid)
returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
  requested_email text;
  matching_user_id uuid;
  email_occupied boolean;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id;
  if not found then raise exception 'provisioning request not found'; end if;
  requested_email := lower(case when request_row.operation = 'school_create'
    then request_row.payload->>'admin_email' else request_row.payload->>'email' end);
  select au.id into matching_user_id from auth.users au
   where lower(au.email) = requested_email
     and au.raw_user_meta_data->>'nexasms_provisioning_request_id' = p_request_id::text
   limit 1;
  select exists(select 1 from auth.users au where lower(au.email) = requested_email) into email_occupied;
  return jsonb_build_object('auth_user_id', matching_user_id,
    'email_occupied', coalesce(email_occupied, false) and matching_user_id is null);
end
$function$;
alter function public.lookup_provisioning_identity(uuid) owner to postgres;
revoke all on function public.lookup_provisioning_identity(uuid) from public, anon, authenticated;
grant execute on function public.lookup_provisioning_identity(uuid) to service_role;

create or replace function public.transition_provisioning_request(
  p_request_id uuid,
  p_next_state text,
  p_error_code text default null,
  p_recovery_actor_auth_user_id uuid default null,
  p_lease_token uuid default null
) returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
  actor_profile_id integer;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found then raise exception 'provisioning request not found'; end if;

  if p_recovery_actor_auth_user_id is not null then
    select u.id into actor_profile_id from public.users u
     where u.auth_user_id = p_recovery_actor_auth_user_id and u.user_type = 'super_admin' and u.school_id is null;
    if actor_profile_id is null or private.profile_has_permission(actor_profile_id, 'platform.schools.manage') is not true then
      raise exception 'recovery actor is not authorized';
    end if;
    if p_lease_token is not null then raise exception 'operator transition cannot supply a worker lease'; end if;
  else
    if p_lease_token is null
       or request_row.lease_token is distinct from p_lease_token
       or request_row.lease_expires_at is null
       or request_row.lease_expires_at <= clock_timestamp()
       or request_row.state in ('cancelling', 'failed_terminal', 'completed') then
      raise exception 'provisioning worker does not own a live lease';
    end if;
  end if;

  if p_recovery_actor_auth_user_id is not null and p_next_state = request_row.state then
    null;
  elsif p_next_state = 'profile_created' then
    if request_row.state not in ('identity_created', 'profile_created') then raise exception 'invalid provisioning state transition'; end if;
  elsif p_next_state = 'completed' then
    if request_row.state not in ('profile_created', 'completed') then raise exception 'invalid provisioning state transition'; end if;
  elsif p_next_state = 'failed_recoverable' then
    if request_row.state not in ('pending', 'identity_created', 'failed_recoverable') then raise exception 'invalid provisioning state transition'; end if;
  elsif p_next_state = 'failed_terminal' then
    if request_row.state not in ('pending', 'identity_created', 'failed_recoverable', 'failed_terminal') then raise exception 'invalid provisioning state transition'; end if;
  elsif p_next_state = 'pending' then
    if p_recovery_actor_auth_user_id is null or request_row.state <> 'failed_recoverable' then raise exception 'only an authorized operator may resume a recoverable request'; end if;
  else
    raise exception 'unsupported provisioning state transition';
  end if;

  update private.provisioning_requests set state = p_next_state,
    error_code = case when p_next_state in ('failed_recoverable', 'failed_terminal') then p_error_code else null end,
    payload = case when p_next_state in ('completed', 'failed_terminal') then '{}'::jsonb else payload end,
    recovered_by = case when p_recovery_actor_auth_user_id is not null then p_recovery_actor_auth_user_id else recovered_by end,
    recovered_at = case when p_recovery_actor_auth_user_id is not null then now() else recovered_at end,
    recovery_channel = case when p_recovery_actor_auth_user_id is not null then 'platform_owner' else recovery_channel end,
    attempt_count = case when p_recovery_actor_auth_user_id is not null then attempt_count + 1 else attempt_count end,
    last_attempt_at = case when p_recovery_actor_auth_user_id is not null then now() else last_attempt_at end,
    lease_token = case when p_next_state in ('failed_recoverable', 'failed_terminal', 'completed')
      or (p_next_state = 'pending' and request_row.state = 'failed_recoverable') then null else lease_token end,
    lease_expires_at = case when p_next_state in ('failed_recoverable', 'failed_terminal', 'completed')
      or (p_next_state = 'pending' and request_row.state = 'failed_recoverable') then null else lease_expires_at end,
    cancelled_by = case when p_next_state = 'failed_terminal' and p_error_code = 'cancelled' then p_recovery_actor_auth_user_id else cancelled_by end,
    cancelled_at = case when p_next_state = 'failed_terminal' and p_error_code = 'cancelled' then now() else cancelled_at end,
    updated_at = now()
   where request_id = p_request_id returning * into request_row;
  return jsonb_build_object('request_id', request_row.request_id, 'operation', request_row.operation,
    'state', request_row.state, 'auth_user_id', request_row.auth_user_id, 'profile_id', request_row.profile_id,
    'school_id', request_row.school_id, 'result', request_row.result, 'error_code', request_row.error_code);
end
$function$;
alter function public.transition_provisioning_request(uuid, text, text, uuid, uuid) owner to postgres;
revoke all on function public.transition_provisioning_request(uuid, text, text, uuid, uuid) from public, anon, authenticated;
grant execute on function public.transition_provisioning_request(uuid, text, text, uuid, uuid) to service_role;

create or replace function public.get_provisioning_request(p_request_id uuid)
returns jsonb
language sql
stable
security definer
set search_path = ''
set row_security = off
as $function$
  select jsonb_build_object('request_id', r.request_id, 'operation', r.operation, 'actor_auth_user_id', r.actor_auth_user_id,
    'payload', r.payload, 'payload_hash', r.payload_hash, 'state', r.state, 'auth_user_id', r.auth_user_id,
    'profile_id', r.profile_id, 'school_id', r.school_id, 'result', r.result, 'error_code', r.error_code,
    'attempt_count', r.attempt_count, 'created_at', r.created_at, 'updated_at', r.updated_at,
    'recovered_by', r.recovered_by, 'recovered_at', r.recovered_at,
    'recovery_channel', r.recovery_channel, 'cancelled_by', r.cancelled_by, 'cancelled_at', r.cancelled_at,
    'lease_expires_at', r.lease_expires_at)
  from private.provisioning_requests r where r.request_id = p_request_id
$function$;
alter function public.get_provisioning_request(uuid) owner to postgres;
revoke all on function public.get_provisioning_request(uuid) from public, anon, authenticated;
grant execute on function public.get_provisioning_request(uuid) to service_role;

-- A lease serializes external Auth work as well as database work. A crashed
-- Edge worker becomes recoverable after ten minutes; cancellation is blocked
-- until the request has been reconciled into a failed state.
create or replace function public.claim_provisioning_request(p_request_id uuid, p_lease_token uuid)
returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
begin
  if p_lease_token is null then raise exception 'provisioning lease token is required'; end if;
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found then raise exception 'provisioning request not found'; end if;
  if request_row.state = 'completed' then
    return jsonb_build_object('request_id', request_row.request_id, 'operation', request_row.operation,
      'payload', request_row.payload, 'state', request_row.state, 'auth_user_id', request_row.auth_user_id,
      'profile_id', request_row.profile_id, 'school_id', request_row.school_id, 'result', request_row.result);
  end if;
  if request_row.state not in ('pending', 'identity_created', 'profile_created') then
    raise exception 'provisioning request is not claimable in its current state';
  end if;
  if request_row.lease_expires_at > clock_timestamp() and request_row.lease_token is distinct from p_lease_token then
    raise exception 'provisioning request is already being processed';
  end if;
  update private.provisioning_requests set lease_token = p_lease_token,
    lease_expires_at = clock_timestamp() + interval '10 minutes', attempt_count = attempt_count + 1,
    last_attempt_at = clock_timestamp(), updated_at = clock_timestamp()
   where request_id = p_request_id returning * into request_row;
  return jsonb_build_object('request_id', request_row.request_id, 'operation', request_row.operation,
    'actor_auth_user_id', request_row.actor_auth_user_id, 'payload', request_row.payload,
    'payload_hash', request_row.payload_hash, 'state', request_row.state, 'auth_user_id', request_row.auth_user_id,
    'profile_id', request_row.profile_id, 'school_id', request_row.school_id, 'result', request_row.result,
    'error_code', request_row.error_code, 'attempt_count', request_row.attempt_count);
end
$function$;
alter function public.claim_provisioning_request(uuid, uuid) owner to postgres;
revoke all on function public.claim_provisioning_request(uuid, uuid) from public, anon, authenticated;
grant execute on function public.claim_provisioning_request(uuid, uuid) to service_role;

-- The random lease token is the per-invocation worker capability. Renewal is
-- serialized with claims and writes by locking this exact ledger row. An
-- expired or superseded worker cannot extend or use its former lease.
create or replace function public.renew_provisioning_lease(p_request_id uuid, p_lease_token uuid)
returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
begin
  if p_lease_token is null then raise exception 'provisioning lease token is required'; end if;
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found then raise exception 'provisioning request not found'; end if;
  if request_row.state not in ('pending', 'identity_created', 'profile_created')
     or request_row.state = 'cancelling'
     or request_row.lease_token is distinct from p_lease_token
     or request_row.lease_expires_at is null
     or request_row.lease_expires_at <= clock_timestamp() then
    raise exception 'provisioning worker does not own a renewable live lease';
  end if;
  update private.provisioning_requests
     set lease_expires_at = clock_timestamp() + interval '10 minutes',
         updated_at = clock_timestamp()
   where request_id = p_request_id
   returning * into request_row;
  return jsonb_build_object('request_id', request_row.request_id,
    'state', request_row.state, 'lease_expires_at', request_row.lease_expires_at);
end
$function$;
alter function public.renew_provisioning_lease(uuid, uuid) owner to postgres;
revoke all on function public.renew_provisioning_lease(uuid, uuid) from public, anon, authenticated;
grant execute on function public.renew_provisioning_lease(uuid, uuid) to service_role;

create or replace function public.begin_provisioning_cancellation(p_request_id uuid, p_operator_auth_user_id uuid)
returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
  operator_profile_id integer;
  requested_email text;
  orphan_auth_user_id uuid;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found then raise exception 'provisioning request not found'; end if;
  if p_operator_auth_user_id is null then
    if request_row.operation <> 'owner_bootstrap' then raise exception 'bootstrap operator cannot recover this operation'; end if;
  else
    select u.id into operator_profile_id from public.users u
     where u.auth_user_id = p_operator_auth_user_id and u.user_type = 'super_admin' and u.school_id is null;
    if operator_profile_id is null or private.profile_has_permission(operator_profile_id, 'platform.schools.manage') is not true then
      raise exception 'recovery operator is not authorized';
    end if;
  end if;
  if request_row.auth_user_id is null and request_row.payload <> '{}'::jsonb then
    requested_email := lower(case when request_row.operation = 'school_create'
      then request_row.payload->>'admin_email' else request_row.payload->>'email' end);
    select au.id into orphan_auth_user_id from auth.users au
     where lower(au.email) = requested_email
       and au.raw_user_meta_data->>'nexasms_provisioning_request_id' = p_request_id::text
     limit 1;
    if orphan_auth_user_id is not null then
      update private.provisioning_requests set auth_user_id = orphan_auth_user_id, updated_at = now()
       where request_id = p_request_id returning * into request_row;
    end if;
  end if;
  if request_row.profile_id is not null or request_row.state in ('profile_created', 'completed')
     or (request_row.state = 'failed_terminal' and request_row.error_code = 'cancelled')
     or request_row.state not in ('failed_recoverable', 'failed_terminal', 'cancelling')
     or request_row.lease_expires_at > now()
     or (request_row.auth_user_id is not null and exists (select 1 from public.users u where u.auth_user_id = request_row.auth_user_id)) then
    raise exception 'request cannot be cancelled in its current state';
  end if;
  update private.provisioning_requests set state = 'cancelling', recovered_by = p_operator_auth_user_id,
    recovered_at = now(),
    recovery_channel = case when p_operator_auth_user_id is null then 'bootstrap_operator' else 'platform_owner' end,
    lease_token = null, lease_expires_at = null,
    updated_at = now() where request_id = p_request_id returning * into request_row;
  return jsonb_build_object('request_id', request_row.request_id, 'state', request_row.state,
    'auth_user_id', request_row.auth_user_id, 'payload', request_row.payload);
end
$function$;
alter function public.begin_provisioning_cancellation(uuid, uuid) owner to postgres;
revoke all on function public.begin_provisioning_cancellation(uuid, uuid) from public, anon, authenticated;
grant execute on function public.begin_provisioning_cancellation(uuid, uuid) to service_role;

create or replace function public.finish_provisioning_cancellation(p_request_id uuid, p_operator_auth_user_id uuid, p_succeeded boolean)
returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found or request_row.state <> 'cancelling' or request_row.recovered_by is distinct from p_operator_auth_user_id then
    raise exception 'cancellation is not owned by this recovery operation';
  end if;
  if p_operator_auth_user_id is null and request_row.operation <> 'owner_bootstrap' then
    raise exception 'bootstrap operator cannot cancel this operation';
  end if;
  if p_succeeded then
    update private.provisioning_requests set state = 'failed_terminal', error_code = 'cancelled', payload = '{}'::jsonb,
      cancelled_by = p_operator_auth_user_id, cancelled_at = now(), updated_at = now()
     where request_id = p_request_id returning * into request_row;
  else
    update private.provisioning_requests set state = 'failed_recoverable', error_code = 'compensation_failed',
      updated_at = now() where request_id = p_request_id returning * into request_row;
  end if;
  return jsonb_build_object('request_id', request_row.request_id, 'state', request_row.state, 'error_code', request_row.error_code);
end
$function$;
alter function public.finish_provisioning_cancellation(uuid, uuid, boolean) owner to postgres;
revoke all on function public.finish_provisioning_cancellation(uuid, uuid, boolean) from public, anon, authenticated;
grant execute on function public.finish_provisioning_cancellation(uuid, uuid, boolean) to service_role;

-- A distinct bootstrap recovery RPC lets the original trusted operator recover
-- the first-owner saga before any platform-owner profile exists. Its only
-- caller is the Edge Function that validates the operator secret; the secret
-- itself is never sent to or stored by PostgreSQL.
create or replace function public.resume_owner_bootstrap_provisioning(p_request_id uuid)
returns jsonb
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  request_row private.provisioning_requests%rowtype;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found or request_row.operation <> 'owner_bootstrap'
     or request_row.state not in ('failed_recoverable', 'pending', 'identity_created', 'profile_created') then
    raise exception 'owner bootstrap request is not recoverable';
  end if;
  update private.provisioning_requests set
    state = case when request_row.state = 'failed_recoverable' then 'pending' else request_row.state end,
    error_code = null, attempt_count = attempt_count + 1, last_attempt_at = now(),
    lease_token = case when request_row.state = 'failed_recoverable' then null else lease_token end,
    lease_expires_at = case when request_row.state = 'failed_recoverable' then null else lease_expires_at end,
    recovered_by = null, recovered_at = now(), recovery_channel = 'bootstrap_operator', updated_at = now()
   where request_id = p_request_id returning * into request_row;
  return jsonb_build_object('request_id', request_row.request_id, 'state', request_row.state);
end
$function$;
alter function public.resume_owner_bootstrap_provisioning(uuid) owner to postgres;
revoke all on function public.resume_owner_bootstrap_provisioning(uuid) from public, anon, authenticated;
grant execute on function public.resume_owner_bootstrap_provisioning(uuid) to service_role;

create table private.username_login_attempts (
  id bigint generated always as identity primary key,
  identifier_hash text not null check (identifier_hash ~ '^[a-f0-9]{64}$'),
  ip_hash text not null check (ip_hash ~ '^[a-f0-9]{64}$'),
  attempted_at timestamptz not null default now()
);
alter table private.username_login_attempts enable row level security;
alter table private.username_login_attempts force row level security;
revoke all on private.username_login_attempts from public, anon, authenticated, service_role;
create index username_login_attempts_identifier_time_idx on private.username_login_attempts(identifier_hash, attempted_at desc);
create index username_login_attempts_ip_time_idx on private.username_login_attempts(ip_hash, attempted_at desc);

create or replace function public.consume_username_login_attempt(
  p_identifier_hash text,
  p_ip_hash text
) returns boolean
language plpgsql
security definer
set search_path = ''
set row_security = off
as $function$
declare
  identifier_count integer;
  ip_count integer;
begin
  if p_identifier_hash is null or p_ip_hash is null
     or p_identifier_hash !~ '^[a-f0-9]{64}$' or p_ip_hash !~ '^[a-f0-9]{64}$' then
    raise exception 'invalid login attempt hash';
  end if;

  perform pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('id:' || p_identifier_hash, 0));
  perform pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('ip:' || p_ip_hash, 0));

  select count(*) into identifier_count from private.username_login_attempts a
  where a.identifier_hash = p_identifier_hash and a.attempted_at > now() - interval '15 minutes';
  select count(*) into ip_count from private.username_login_attempts a
  where a.ip_hash = p_ip_hash and a.attempted_at > now() - interval '15 minutes';

  delete from private.username_login_attempts a where a.attempted_at < now() - interval '1 day';
  if identifier_count >= 10 or ip_count >= 40 then
    return false;
  end if;
  insert into private.username_login_attempts(identifier_hash, ip_hash) values (p_identifier_hash, p_ip_hash);
  return true;
end
$function$;
alter function public.consume_username_login_attempt(text, text) owner to postgres;
revoke all on function public.consume_username_login_attempt(text, text) from public, anon, authenticated;
grant execute on function public.consume_username_login_attempt(text, text) to service_role;

create or replace function public.get_my_auth_context()
returns jsonb
language plpgsql
stable
security definer
set search_path = ''
set row_security = off
as $function$
declare
  result jsonb;
begin
  if auth.uid() is null then
    return null;
  end if;

  select jsonb_build_object(
    'profile', jsonb_build_object(
      'id', u.id,
      'name', u.name,
      'email', u.email,
      'username', u.username,
      'photo', u.photo,
      'user_type', u.user_type,
      'school_id', u.school_id
    ),
    'school', case when u.school_id is null then null else jsonb_build_object(
      'id', s.id, 'name', s.name, 'slug', s.slug, 'status', s.status,
      'timezone', s.timezone, 'currency', s.currency
    ) end,
    'permissions', coalesce((
      select jsonb_agg(permission_name order by permission_name)
      from (
        select p.name as permission_name,
               coalesce(up.allowed, rp.permission_id is not null) as allowed
        from public.permissions p
        left join public.role_permissions rp
          on rp.role_id = r.id and rp.permission_id = p.id
        left join public.user_permissions up
          on up.user_id = u.id and up.permission_id = p.id
        where ((u.user_type = 'super_admin') = (p.name like 'platform.%'))
      ) effective
      where allowed
    ), '[]'::jsonb)
  )
    into result
  from public.users u
  join public.roles r on r.name = u.user_type
  left join public.schools s on s.id = u.school_id
  where u.auth_user_id = auth.uid()
    and (
      (u.user_type = 'super_admin' and u.school_id is null)
      or (u.user_type <> 'super_admin' and s.id is not null and s.status in ('active', 'trial'))
    )
  limit 1;

  return result;
end
$function$;
alter function public.get_my_auth_context() owner to postgres;
revoke all on function public.get_my_auth_context() from public, anon, service_role;
grant execute on function public.get_my_auth_context() to authenticated;

-- These SECURITY INVOKER functions run through PostgREST with the Edge
-- Function's service_role JWT. The existing authority triggers verify that
-- trusted execution context. Do not call these from browser code.
create or replace function public.provision_platform_owner_profile(
  p_request_id uuid,
  p_auth_user_id uuid,
  p_name text,
  p_lease_token uuid
) returns integer
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  auth_email text;
  v_profile_id integer;
  request_row private.provisioning_requests%rowtype;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found or request_row.operation <> 'owner_bootstrap' or request_row.auth_user_id is distinct from p_auth_user_id
     or request_row.payload->>'name' is distinct from btrim(p_name) then
    raise exception 'owner request does not match the durable provisioning request';
  end if;
  if request_row.state not in ('identity_created', 'profile_created')
     or request_row.lease_token is distinct from p_lease_token
     or request_row.lease_expires_at is null
     or request_row.lease_expires_at <= clock_timestamp() then
    raise exception 'provisioning worker does not own a live lease';
  end if;
  if request_row.state in ('profile_created', 'completed') then return request_row.profile_id; end if;
  if request_row.state <> 'identity_created' then raise exception 'owner request is not ready for profile creation'; end if;
  select au.email into auth_email from auth.users au where au.id = p_auth_user_id;
  if auth_email is null then
    raise exception 'provisioning identity does not exist in Supabase Auth';
  end if;
  if lower(request_row.payload->>'email') is distinct from lower(auth_email) then
    raise exception 'owner email does not match the durable provisioning request';
  end if;
  perform pg_catalog.pg_advisory_xact_lock(7128649234::bigint);
  select id into v_profile_id from public.users
   where user_type = 'super_admin' and auth_user_id = p_auth_user_id;
  if found then
    update private.provisioning_requests set state = 'profile_created', profile_id = v_profile_id,
      result = jsonb_build_object('profile_id', v_profile_id), updated_at = clock_timestamp()
     where request_id = p_request_id;
    return v_profile_id;
  end if;
  if exists (select 1 from public.users where user_type = 'super_admin') then
    raise exception 'platform owner is already provisioned';
  end if;
  if not exists (select 1 from public.roles where name = 'super_admin' and scope = 'platform') then
    raise exception 'the existing platform super_admin role is unavailable';
  end if;
  if nullif(btrim(p_name), '') is null or length(btrim(p_name)) > 150 then
    raise exception 'owner name is required and must be at most 150 characters';
  end if;

  insert into public.users (name, email, username, code, user_type, school_id, auth_user_id, password)
  values (btrim(p_name), auth_email, auth_email, upper(replace(gen_random_uuid()::text, '-', '')),
          'super_admin', null, p_auth_user_id, null)
  returning id into v_profile_id;
  update private.provisioning_requests set state = 'profile_created', profile_id = v_profile_id,
    result = jsonb_build_object('profile_id', v_profile_id), updated_at = clock_timestamp()
   where request_id = p_request_id;
  return v_profile_id;
end
$function$;
alter function public.provision_platform_owner_profile(uuid, uuid, text, uuid) owner to postgres;
revoke all on function public.provision_platform_owner_profile(uuid, uuid, text, uuid) from public, anon, authenticated;
grant execute on function public.provision_platform_owner_profile(uuid, uuid, text, uuid) to service_role;

create or replace function public.provision_school_and_first_admin(
  p_request_id uuid,
  p_auth_user_id uuid,
  p_actor_auth_user_id uuid,
  p_school_name text,
  p_school_code text,
  p_registration_number text,
  p_school_email text,
  p_phone text,
  p_address text,
  p_timezone text,
  p_currency text,
  p_status text,
  p_admin_name text,
  p_lease_token uuid
) returns table (school_id integer, admin_profile_id integer)
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  auth_email text;
  actor_profile_id integer;
  school_row_id integer;
  profile_row_id integer;
  request_row private.provisioning_requests%rowtype;
  slug_base text;
  slug_value text;
  suffix integer := 2;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found or request_row.operation <> 'school_create' or request_row.actor_auth_user_id is distinct from p_actor_auth_user_id
     or request_row.auth_user_id is distinct from p_auth_user_id then
    raise exception 'school request does not match the durable provisioning request';
  end if;
  if request_row.state not in ('identity_created', 'profile_created')
     or request_row.lease_token is distinct from p_lease_token
     or request_row.lease_expires_at is null
     or request_row.lease_expires_at <= clock_timestamp() then
    raise exception 'provisioning worker does not own a live lease';
  end if;
  if request_row.state in ('profile_created', 'completed') then
    return query select (request_row.result->>'school_id')::integer, (request_row.result->>'admin_profile_id')::integer;
    return;
  end if;
  if request_row.state <> 'identity_created' then raise exception 'school request is not ready for profile creation'; end if;
  select au.email into auth_email from auth.users au where au.id = p_auth_user_id;
  if auth_email is null then
    raise exception 'administrator identity does not exist in Supabase Auth';
  end if;
  if nullif(btrim(p_school_name), '') is null or length(btrim(p_school_name)) > 255
     or nullif(btrim(p_school_code), '') is null or length(p_school_code) > 50
     or p_school_code !~ '^[A-Za-z0-9_-]+$'
     or nullif(btrim(p_admin_name), '') is null or length(btrim(p_admin_name)) > 255
     or p_currency is null or p_currency !~ '^[A-Za-z]{3}$'
     or nullif(btrim(p_timezone), '') is null or length(p_timezone) > 64
     or p_status is null or p_status not in ('active', 'inactive', 'suspended', 'trial') then
    raise exception 'school or first administrator details are invalid';
  end if;
  if request_row.payload->>'name' is distinct from btrim(p_school_name)
     or request_row.payload->>'code' is distinct from btrim(p_school_code)
     or request_row.payload->>'registration_number' is distinct from nullif(btrim(p_registration_number), '')
     or request_row.payload->>'email' is distinct from nullif(lower(btrim(p_school_email)), '')
     or request_row.payload->>'phone' is distinct from nullif(btrim(p_phone), '')
     or request_row.payload->>'address' is distinct from nullif(btrim(p_address), '')
     or request_row.payload->>'timezone' is distinct from btrim(p_timezone)
     or upper(request_row.payload->>'currency') is distinct from upper(p_currency)
     or request_row.payload->>'status' is distinct from p_status
     or lower(request_row.payload->>'admin_email') is distinct from lower(auth_email)
     or request_row.payload->>'admin_name' is distinct from btrim(p_admin_name) then
    raise exception 'school details do not match the reserved request';
  end if;
  if not exists (select 1 from public.roles where name = 'admin' and scope = 'school') then
    raise exception 'the existing school admin role is unavailable';
  end if;
  select u.id into actor_profile_id from public.users u
   where u.auth_user_id = p_actor_auth_user_id and u.user_type = 'super_admin' and u.school_id is null;
  if actor_profile_id is null
     or private.profile_has_permission(actor_profile_id, 'platform.schools.manage') is not true then
    raise exception 'school provisioning actor is not the platform owner';
  end if;

  -- The existing SECURITY DEFINER audit trigger validates actor_id against
  -- auth.uid(). Bind the already-verified platform owner as this transaction's
  -- local subject so the service-role write preserves the true audit actor.
  perform pg_catalog.set_config('request.jwt.claim.sub', p_actor_auth_user_id::text, true);

  slug_base := trim(both '-' from regexp_replace(lower(btrim(p_school_name)), '[^a-z0-9]+', '-', 'g'));
  if slug_base = '' then slug_base := 'school'; end if;
  slug_value := slug_base;
  while exists (select 1 from public.schools s where s.slug = slug_value) loop
    slug_value := slug_base || '-' || suffix::text;
    suffix := suffix + 1;
  end loop;

  insert into public.schools (name, code, registration_number, email, phone, address, timezone, currency, status, slug)
  values (btrim(p_school_name), btrim(p_school_code), nullif(btrim(p_registration_number), ''),
          nullif(btrim(p_school_email), ''), nullif(btrim(p_phone), ''), nullif(btrim(p_address), ''),
          btrim(p_timezone), upper(p_currency), p_status, slug_value)
  returning id into school_row_id;

  insert into public.users (name, email, username, code, user_type, school_id, auth_user_id, password)
  values (btrim(p_admin_name), auth_email, auth_email, upper(replace(gen_random_uuid()::text, '-', '')),
          'admin', school_row_id, p_auth_user_id, null)
  returning id into profile_row_id;

  insert into public.audit_logs (school_id, actor_id, action, auditable_type, auditable_id, description, properties)
  values (school_row_id, actor_profile_id, 'school.created', 'App\Models\School', school_row_id,
          'School and first administrator created.', jsonb_build_object('code', btrim(p_school_code), 'status', p_status));

  update private.provisioning_requests set state = 'profile_created', profile_id = profile_row_id,
    school_id = school_row_id, result = jsonb_build_object('school_id', school_row_id, 'admin_profile_id', profile_row_id),
    updated_at = clock_timestamp() where request_id = p_request_id;

  return query select school_row_id, profile_row_id;
end
$function$;
alter function public.provision_school_and_first_admin(uuid, uuid, uuid, text, text, text, text, text, text, text, text, text, text, uuid) owner to postgres;
revoke all on function public.provision_school_and_first_admin(uuid, uuid, uuid, text, text, text, text, text, text, text, text, text, text, uuid) from public, anon, authenticated;
grant execute on function public.provision_school_and_first_admin(uuid, uuid, uuid, text, text, text, text, text, text, text, text, text, text, uuid) to service_role;

create or replace function public.provision_school_staff_profile(
  p_request_id uuid,
  p_auth_user_id uuid,
  p_actor_auth_user_id uuid,
  p_name text,
  p_user_type text,
  p_username text,
  p_gender text,
  p_phone text,
  p_address text,
  p_state_id integer,
  p_lga_id integer,
  p_nal_id integer,
  p_emp_date text,
  p_lease_token uuid
) returns integer
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  auth_email text;
  actor_profile_id integer;
  actor_school_id integer;
  v_profile_id integer;
  request_row private.provisioning_requests%rowtype;
begin
  select * into request_row from private.provisioning_requests r where r.request_id = p_request_id for update;
  if not found or request_row.operation <> 'school_user_create' or request_row.actor_auth_user_id is distinct from p_actor_auth_user_id
     or request_row.auth_user_id is distinct from p_auth_user_id then
    raise exception 'school-user request does not match the durable provisioning request';
  end if;
  if request_row.state not in ('identity_created', 'profile_created')
     or request_row.lease_token is distinct from p_lease_token
     or request_row.lease_expires_at is null
     or request_row.lease_expires_at <= clock_timestamp() then
    raise exception 'provisioning worker does not own a live lease';
  end if;
  if request_row.state in ('profile_created', 'completed') then return request_row.profile_id; end if;
  if request_row.state <> 'identity_created' then raise exception 'school-user request is not ready for profile creation'; end if;
  select au.email into auth_email from auth.users au where au.id = p_auth_user_id;
  if auth_email is null then raise exception 'staff identity does not exist in Supabase Auth'; end if;
  if p_user_type not in ('teacher', 'accountant', 'librarian', 'student', 'parent') then
    raise exception 'this workflow cannot provision the requested user type';
  end if;
  select u.id, u.school_id into actor_profile_id, actor_school_id from public.users u
   where u.auth_user_id = p_actor_auth_user_id and u.user_type = 'admin' and u.school_id is not null;
  if actor_profile_id is null
     or private.profile_has_permission(actor_profile_id, 'school.manage') is not true then
    raise exception 'school provisioning actor lacks school.manage permission';
  end if;
  if not exists (select 1 from public.schools s where s.id = actor_school_id and s.status in ('active', 'trial')) then
    raise exception 'school is unavailable';
  end if;
  if not exists (select 1 from public.roles r where r.name = p_user_type and r.scope = 'school') then
    raise exception 'requested user type is not a school role';
  end if;
  if nullif(btrim(p_name), '') is null or length(btrim(p_name)) < 6 or length(btrim(p_name)) > 150
     or nullif(btrim(p_username), '') is null or length(p_username) > 100
     or nullif(btrim(p_gender), '') is null
     or nullif(btrim(p_address), '') is null or length(btrim(p_address)) < 6 or length(btrim(p_address)) > 120
     or not exists (select 1 from public.lgas l where l.id = p_lga_id and l.state_id = p_state_id)
     or not exists (select 1 from public.states st where st.id = p_state_id and st.name in ('Central', 'Eastern', 'Northern', 'Western'))
     or not exists (select 1 from public.nationalities n where n.id = p_nal_id) then
    raise exception 'staff details do not satisfy required profile validation';
  end if;
  if request_row.payload->>'name' is distinct from btrim(p_name)
     or lower(request_row.payload->>'email') is distinct from lower(auth_email)
     or request_row.payload->>'user_type' is distinct from p_user_type
     or request_row.payload->>'username' is distinct from btrim(p_username)
     or request_row.payload->>'gender' is distinct from btrim(p_gender)
     or request_row.payload->>'phone' is distinct from nullif(btrim(p_phone), '')
     or request_row.payload->>'address' is distinct from btrim(p_address)
     or request_row.payload->>'state_id' is distinct from p_state_id::text
     or request_row.payload->>'lga_id' is distinct from p_lga_id::text
     or request_row.payload->>'nal_id' is distinct from p_nal_id::text
     or request_row.payload->>'emp_date' is distinct from nullif(btrim(p_emp_date), '') then
    raise exception 'school-user details do not match the reserved request';
  end if;

  -- Use only the actor resolved from the verified JWT/profile and checked for
  -- school.manage above. The audit trigger independently validates this subject.
  perform pg_catalog.set_config('request.jwt.claim.sub', p_actor_auth_user_id::text, true);

  insert into public.users (name, email, username, code, user_type, school_id, auth_user_id,
                            gender, phone, address, state_id, lga_id, nal_id, password)
  values (btrim(p_name), auth_email, btrim(p_username), upper(replace(gen_random_uuid()::text, '-', '')),
          p_user_type, actor_school_id, p_auth_user_id, btrim(p_gender), nullif(btrim(p_phone), ''),
          btrim(p_address), p_state_id, p_lga_id, p_nal_id, null)
  returning id into v_profile_id;

  if p_user_type in ('teacher', 'accountant', 'librarian') then
    insert into public.staff_records (user_id, code, emp_date, school_id)
    values (v_profile_id, btrim(p_username), nullif(btrim(p_emp_date), ''), actor_school_id);
  end if;
  insert into public.audit_logs (school_id, actor_id, action, auditable_type, auditable_id, description, properties)
  values (actor_school_id, actor_profile_id, 'user.created', 'App\User', v_profile_id,
          'School user created.', jsonb_build_object('user_type', p_user_type));
  update private.provisioning_requests set state = 'profile_created', profile_id = v_profile_id,
    school_id = actor_school_id, result = jsonb_build_object('profile_id', v_profile_id),
    updated_at = clock_timestamp()
   where request_id = p_request_id;
  return v_profile_id;
end
$function$;
alter function public.provision_school_staff_profile(uuid, uuid, uuid, text, text, text, text, text, text, integer, integer, integer, text, uuid) owner to postgres;
revoke all on function public.provision_school_staff_profile(uuid, uuid, uuid, text, text, text, text, text, text, integer, integer, integer, text, uuid) from public, anon, authenticated;
grant execute on function public.provision_school_staff_profile(uuid, uuid, uuid, text, text, text, text, text, text, integer, integer, integer, text, uuid) to service_role;

commit;
