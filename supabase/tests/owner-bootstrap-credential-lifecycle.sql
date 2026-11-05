-- Isolated local Supabase test only. Runs inside a transaction and rolls back.
-- Requires the fresh migration state before any platform owner is created.
begin;

do $test$
declare
  bootstrap_request_id constant uuid := 'f0000000-0000-4000-8000-000000000001';
  current_hash text;
  consumed_id uuid;
begin
  if exists (select 1 from public.users where user_type = 'super_admin') then
    raise exception 'credential lifecycle test requires a fresh database without a platform owner';
  end if;

  -- Rotation is allowed before first consumption.
  perform private.arm_owner_bootstrap_credential(repeat('a', 64), now() + interval '1 hour');
  perform private.arm_owner_bootstrap_credential(repeat('b', 64), now() + interval '1 hour');
  select token_sha256 into current_hash from private.owner_bootstrap_credentials where singleton;
  if current_hash <> repeat('b', 64) then
    raise exception 'unconsumed bootstrap verifier did not rotate';
  end if;

  insert into private.provisioning_requests(request_id, operation, actor_auth_user_id, payload, payload_hash, state, error_code)
  values (bootstrap_request_id, 'owner_bootstrap', null,
    '{"email":"lifecycle@example.test","name":"Lifecycle Owner"}'::jsonb,
    repeat('c', 64), 'failed_recoverable', 'auth_invite_failed');
  update private.owner_bootstrap_credentials set consumed_request_id = bootstrap_request_id,
    consumed_at = now(), updated_at = now() where singleton;

  -- Consumption cannot be rotated while the original request may still create an owner.
  begin
    perform private.arm_owner_bootstrap_credential(repeat('d', 64), now() + interval '1 hour');
    raise exception 'expected consumed bootstrap rotation to be rejected';
  exception when raise_exception then
    if sqlerrm <> 'consumed bootstrap can only be re-armed after an explicitly completed cancellation' then
      raise;
    end if;
  end;

  -- Only an explicit terminal cancellation with no linked profile permits re-arming.
  update private.provisioning_requests set state = 'failed_terminal', error_code = 'cancelled', payload = '{}'
   where provisioning_requests.request_id = bootstrap_request_id;
  perform private.arm_owner_bootstrap_credential(repeat('d', 64), now() + interval '1 hour');
  select token_sha256, consumed_request_id into current_hash, consumed_id
    from private.owner_bootstrap_credentials where singleton;
  if current_hash <> repeat('d', 64) or consumed_id is not null then
    raise exception 'bootstrap credential did not re-arm after completed cancellation';
  end if;
end
$test$;

rollback;
