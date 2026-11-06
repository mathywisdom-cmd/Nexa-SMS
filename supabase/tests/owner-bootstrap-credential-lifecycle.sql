-- Isolated local Supabase test only. Runs inside a transaction and rolls back.
-- Requires the fresh migration state before any platform owner is created.
begin;

do $test$
declare
  bootstrap_request_id constant uuid := 'f0000000-0000-4000-8000-000000000001';
  valid_payload_request_id constant uuid := 'f0000000-0000-4000-8000-000000000002';
  current_hash text;
  consumed_id uuid;
  accepted jsonb;
  candidate jsonb;
  invalid_payloads jsonb[] := array[
    '{"email":"unexpected@example.test","name":"Unexpected Key Owner","role":"super_admin"}'::jsonb,
    '{"name":"Missing Email Owner"}'::jsonb,
    '{"email":"missing-name@example.test"}'::jsonb,
    '{"email":null,"name":"Null Email Owner"}'::jsonb,
    '{"email":"null-name@example.test","name":null}'::jsonb,
    '{"email":"not-an-email","name":"Invalid Email Owner"}'::jsonb,
    '{"email":"short-name@example.test","name":"Short"}'::jsonb,
    jsonb_build_object('email', 'long-name@example.test', 'name', repeat('x', 151)),
    'null'::jsonb,
    NULL::jsonb,
    '[]'::jsonb
  ];
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

  -- The exact email/name object is accepted. Validation occurs before token
  -- consumption for every invalid shape, so the same one-time credential can
  -- be used to prove rejection without rotating or weakening it.
  accepted := public.reserve_owner_bootstrap_request(
    valid_payload_request_id,
    '{"email":"valid-payload@example.test","name":"Valid Payload Owner"}'::jsonb,
    repeat('e', 64), repeat('d', 64), repeat('f', 64)
  );
  if accepted->>'ok' is distinct from 'true' then
    raise exception 'bootstrap payload containing exactly email and name was rejected';
  end if;

  foreach candidate in array invalid_payloads loop
    begin
      perform public.reserve_owner_bootstrap_request(
        'f0000000-0000-4000-8000-000000000003'::uuid,
        candidate, repeat('e', 64), repeat('d', 64), repeat('f', 64)
      );
      raise exception 'invalid bootstrap payload was accepted: %', candidate;
    exception when raise_exception then
      if sqlerrm <> 'invalid owner bootstrap request' then
        raise;
      end if;
    end;
  end loop;
end
$test$;

rollback;
