import { createClient, type SupabaseClient } from 'npm:@supabase/supabase-js@2.119.0'
import { reserveProvisioningRequest } from '../functions/_shared/http.ts'

const baseUrl = Deno.env.get('SUPABASE_URL')
const anonKey = Deno.env.get('SUPABASE_ANON_KEY')
const serviceKey = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')
const functionsUrl = Deno.env.get('SUPABASE_FUNCTIONS_URL')
const databaseUrl = Deno.env.get('SUPABASE_DB_URL')
if (!baseUrl || !anonKey || !serviceKey || !functionsUrl || !databaseUrl) {
  throw new Error('This suite must run only against the CI Supabase local stack.')
}

const service = createClient(baseUrl, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } })
const suffix = crypto.randomUUID().replaceAll('-', '')
const password = `ci-${crypto.randomUUID()}-Aa9!`

function assert(condition: unknown, message: string): asserts condition {
  if (!condition) throw new Error(message)
}

function requestId() {
  return crypto.randomUUID()
}

async function createIdentity(email: string, request: string) {
  const { data, error } = await service.auth.admin.createUser({
    email,
    password,
    email_confirm: true,
    user_metadata: { nexasms_provisioning_request_id: request },
  })
  if (error || !data.user) throw new Error(`Local Auth identity creation failed: ${error?.message ?? 'missing user'}`)
  return data.user.id
}

async function reserve(
  id: string,
  operation: 'owner_bootstrap' | 'school_create' | 'school_user_create',
  actor: string | null,
  payload: Record<string, unknown>,
) {
  return reserveProvisioningRequest(service, id, operation, actor, payload)
}

async function claimAndBind(id: string, authId: string) {
  const lease = crypto.randomUUID()
  const { data: claimed, error: claimError } = await service.rpc('claim_provisioning_request', {
    p_request_id: id, p_lease_token: lease,
  })
  assert(!claimError && claimed, `request claim failed: ${claimError?.message ?? 'empty result'}`)
  const { error: bindError } = await service.rpc('record_provisioning_identity', {
    p_request_id: id, p_auth_user_id: authId, p_lease_token: lease,
  })
  assert(!bindError, `Auth identity binding failed: ${bindError?.message ?? 'unknown'}`)
  return lease
}

async function complete(id: string, lease: string) {
  const { error } = await service.rpc('transition_provisioning_request', {
    p_request_id: id, p_next_state: 'completed', p_error_code: null,
    p_recovery_actor_auth_user_id: null, p_lease_token: lease,
  })
  assert(!error, `request completion failed: ${error?.message ?? 'unknown'}`)
}

async function createSchoolAdmin(ownerAuthId: string, email: string, code: string) {
  const id = requestId()
  const payload = {
    name: `CI School ${code}`, code, registration_number: null,
    email: null, phone: null, address: null, timezone: 'Africa/Kampala',
    currency: 'UGX', status: 'active', admin_name: `CI Admin ${code}`,
    admin_email: email,
  }
  const authId = await createIdentity(email, id)
  const request = await reserve(id, 'school_create', ownerAuthId, payload)
  const lease = await claimAndBind(id, authId)
  const { data, error } = await service.rpc('provision_school_and_first_admin', {
    p_request_id: id, p_auth_user_id: authId, p_actor_auth_user_id: ownerAuthId,
    p_school_name: payload.name, p_school_code: code, p_registration_number: null,
    p_school_email: null, p_phone: null, p_address: null, p_timezone: payload.timezone,
    p_currency: payload.currency, p_status: payload.status, p_admin_name: payload.admin_name,
    p_lease_token: lease,
  })
  assert(!error && data?.[0]?.school_id, `school RPC failed: ${error?.message ?? 'empty result'}`)
  await complete(request.request_id, lease)
  return { id: Number(data[0].school_id), adminAuthId: authId, adminEmail: email }
}

async function createTeacher(schoolAdmin: { adminAuthId: string }, schoolCode: string) {
  const email = `ci-teacher-${schoolCode.toLowerCase()}-${suffix}@example.test`
  const id = requestId()
  const username = `CI-TEACHER-${schoolCode}-${suffix}`
  const stateResult = await service.from('states').select('id').eq('name', 'Central').limit(1).maybeSingle()
  let stateId = stateResult.data?.id as number | undefined
  if (!stateId) {
    const inserted = await service.from('states').insert({ name: 'Central' }).select('id').single()
    assert(!inserted.error, `CI state fixture insertion failed: ${inserted.error?.message}`)
    stateId = inserted.data.id
  }
  const lgaResult = await service.from('lgas').select('id').eq('state_id', stateId).limit(1).maybeSingle()
  let lgaId = lgaResult.data?.id as number | undefined
  if (!lgaId) {
    const inserted = await service.from('lgas').insert({ state_id: stateId, name: `CI LGA ${suffix}` }).select('id').single()
    assert(!inserted.error, `CI LGA fixture insertion failed: ${inserted.error?.message}`)
    lgaId = inserted.data.id
  }
  const nationalityResult = await service.from('nationalities').select('id').limit(1).maybeSingle()
  let nationalityId = nationalityResult.data?.id as number | undefined
  if (!nationalityId) {
    const inserted = await service.from('nationalities').insert({ name: `CI Nationality ${suffix}` }).select('id').single()
    assert(!inserted.error, `CI nationality fixture insertion failed: ${inserted.error?.message}`)
    nationalityId = inserted.data.id
  }
  const payload = {
    name: `CI Teacher ${schoolCode}`, email, user_type: 'teacher', username,
    gender: 'Other', phone: null, address: 'CI validation address',
    state_id: stateId, lga_id: lgaId, nal_id: nationalityId, emp_date: null,
  }
  const authId = await createIdentity(email, id)
  const request = await reserve(id, 'school_user_create', schoolAdmin.adminAuthId, payload)
  const lease = await claimAndBind(id, authId)
  const { data: profileId, error } = await service.rpc('provision_school_staff_profile', {
    p_request_id: id, p_auth_user_id: authId, p_actor_auth_user_id: schoolAdmin.adminAuthId,
    p_name: payload.name, p_user_type: payload.user_type, p_username: username,
    p_gender: payload.gender, p_phone: null, p_address: payload.address,
    p_state_id: stateId, p_lga_id: lgaId, p_nal_id: nationalityId,
    p_emp_date: null, p_lease_token: lease,
  })
  assert(!error && profileId, `staff provisioning failed: ${error?.message ?? 'empty result'}`)
  await complete(request.request_id, lease)
  return { authId, email, profileId: Number(profileId) }
}

async function signIn(email: string): Promise<SupabaseClient> {
  const client = createClient(baseUrl!, anonKey!, { auth: { persistSession: false, autoRefreshToken: false } })
  const { error } = await client.auth.signInWithPassword({ email, password })
  assert(!error, `local Auth sign-in failed for ${email}: ${error?.message ?? 'unknown'}`)
  return client
}

async function auditInsertWithSubject(subject: string, actorProfileId: number, schoolId: number, action: string) {
  assert(/^[0-9a-f-]{36}$/i.test(subject), 'test Auth subject is not a UUID')
  assert(Number.isInteger(actorProfileId) && Number.isInteger(schoolId), 'test profile/school IDs are not integers')
  const sql = [
    'begin;',
    'set local role service_role;',
    "select pg_catalog.set_config('request.jwt.claim.role', 'service_role', true);",
    `select pg_catalog.set_config('request.jwt.claim.sub', '${subject}', true);`,
    `insert into public.audit_logs (school_id, actor_id, action) values (${schoolId}, ${actorProfileId}, '${action}');`,
    'commit;',
  ].join('\n')
  const process = new Deno.Command('psql', {
    args: ['-X', databaseUrl!, '-v', 'ON_ERROR_STOP=1', '-q', '-c', sql],
    stdout: 'null', stderr: 'piped',
  })
  const result = await process.output()
  return { success: result.code === 0, error: new TextDecoder().decode(result.stderr) }
}

Deno.test('real local Auth, provisioning authorization, tenant isolation, leases, and recovery', async () => {
  const authIds: string[] = []
  try {
    const loginResponse = await fetch(`${functionsUrl}/login-username`, {
      method: 'POST', headers: { apikey: anonKey!, 'content-type': 'application/json', 'cf-connecting-ip': '192.0.2.18' },
      body: JSON.stringify({ identity: '', password: '' }),
    })
    const loginBody = await loginResponse.json()
    assert(loginResponse.status === 401 && loginBody.error === 'invalid_login', 'username-login Edge Function returned an unsafe or unexpected invalid-login response')

    const badBootstrap = await fetch(`${functionsUrl}/bootstrap-owner`, {
      method: 'POST', headers: { apikey: anonKey!, 'content-type': 'application/json', 'x-bootstrap-token': 'incorrect-test-token' },
      body: JSON.stringify({ request_id: requestId(), email: `unused-${suffix}@example.test`, name: 'CI Invalid Bootstrap' }),
    })
    assert(badBootstrap.status === 403, 'owner bootstrap Edge Function accepted an invalid bootstrap token')

    const protectedProvisioning = await fetch(`${functionsUrl}/provision-school`, {
      method: 'POST', headers: { apikey: anonKey!, 'content-type': 'application/json' },
      body: JSON.stringify({}),
    })
    assert(protectedProvisioning.status === 401, 'school provisioning Edge Function did not require a verified Auth JWT')

    const unauthorizedRecovery = await fetch(`${functionsUrl}/provisioning-recovery`, {
      method: 'POST', headers: { apikey: anonKey!, 'content-type': 'application/json' },
      body: JSON.stringify({ request_id: requestId(), action: 'status' }),
    })
    assert(unauthorizedRecovery.status === 401, 'unauthenticated provisioning recovery was not rejected')

    // Two independent bootstrap requests reach the database owner lock in the
    // same concurrent wave. The unique index/lock must permit one profile only.
    const ownerRequests = [requestId(), requestId()]
    const owners = await Promise.all(ownerRequests.map(async (id, index) => {
      const email = `ci-owner-${index}-${suffix}@example.test`
      const authId = await createIdentity(email, id)
      authIds.push(authId)
      const request = await reserve(id, 'owner_bootstrap', null, { email, name: `CI Platform Owner ${index}` })
      const lease = await claimAndBind(id, authId)
      return { id, authId, email, name: `CI Platform Owner ${index}`, lease, request }
    }))
    const ownerAttempts = await Promise.allSettled(owners.map(owner => service.rpc('provision_platform_owner_profile', {
      p_request_id: owner.id, p_auth_user_id: owner.authId, p_name: owner.name, p_lease_token: owner.lease,
    })))
    const successfulOwners = ownerAttempts.filter(result => result.status === 'fulfilled' && !result.value.error)
    const ownerFailureDetails = ownerAttempts.map((result, index) => {
      if (result.status === 'rejected') return `owner${index}: ${String(result.reason?.message ?? 'request rejected')}`
      if (result.value.error) return `owner${index}: ${result.value.error.code ?? 'database_error'} ${result.value.error.message ?? 'no message'}`
      return `owner${index}: succeeded`
    })
    assert(successfulOwners.length === 1,
      `expected exactly one concurrent owner bootstrap winner; got ${successfulOwners.length}; ${ownerFailureDetails.join('; ')}`)
    const ownerIndex = ownerAttempts.findIndex(result => result.status === 'fulfilled' && !result.value.error)
    const owner = owners[ownerIndex]
    const ownerCount = await service.from('users').select('id', { count: 'exact', head: true }).eq('user_type', 'super_admin')
    assert(!ownerCount.error && ownerCount.count === 1, `expected one owner profile; count=${ownerCount.count}`)
    await complete(owner.id, owner.lease)
    const loser = owners[1 - ownerIndex]
    await service.auth.admin.deleteUser(loser.authId)
    authIds.splice(authIds.indexOf(loser.authId), 1)

    // Re-reserving the exact completed request returns its durable state and
    // cannot create another identity/profile.
    const ownerRetry = await reserve(owner.id, 'owner_bootstrap', null, { email: owner.email, name: owner.name })
    assert(ownerRetry.state === 'completed', 'same idempotency key did not return the completed owner request')
    let conflictingRetryRejected = false
    try {
      await reserve(owner.id, 'owner_bootstrap', null, { email: owner.email, name: 'Different Owner Payload' })
    } catch {
      conflictingRetryRejected = true
    }
    assert(conflictingRetryRejected, 'completed idempotency key accepted a different payload')
    const ownerClient = await signIn(owner.email)
    const ownerContext = await ownerClient.rpc('get_my_auth_context')
    assert(!ownerContext.error && ownerContext.data?.profile?.user_type === 'super_admin'
      && ownerContext.data.profile.school_id === null
      && ownerContext.data.permissions.includes('platform.schools.manage'),
    'platform owner Auth identity did not resolve to the platform-only permission context')

    const schoolA = await createSchoolAdmin(owner.authId, `ci-admin-a-${suffix}@example.test`, `CIA${suffix.slice(0, 6)}`)
    authIds.push(schoolA.adminAuthId)
    const schoolB = await createSchoolAdmin(owner.authId, `ci-admin-b-${suffix}@example.test`, `CIB${suffix.slice(0, 6)}`)
    authIds.push(schoolB.adminAuthId)
    const teacherA = await createTeacher(schoolA, 'A')
    authIds.push(teacherA.authId)
    const teacherB = await createTeacher(schoolB, 'B')
    authIds.push(teacherB.authId)

    // A retry after the Auth identity was durably linked must resume in
    // identity_created; returning to pending would make the profile RPC reject
    // the request even though its Auth identity already exists.
    const retryId = requestId()
    const retryEmail = `ci-profile-retry-${suffix}@example.test`
    const retryAuthId = await createIdentity(retryEmail, retryId)
    authIds.push(retryAuthId)
    const retryPayload = {
      name: 'CI Retry Teacher', email: retryEmail, user_type: 'teacher',
      username: `CI-RETRY-${suffix}`, gender: 'Other', phone: null,
      address: 'CI retry validation', state_id: 1, lga_id: 1, nal_id: 1, emp_date: null,
    }
    await reserve(retryId, 'school_user_create', schoolA.adminAuthId, retryPayload)
    const initialRetryLease = await claimAndBind(retryId, retryAuthId)
    const simulatedProfileFailure = await service.rpc('transition_provisioning_request', {
      p_request_id: retryId, p_next_state: 'failed_recoverable', p_error_code: 'profile_write_failed',
      p_recovery_actor_auth_user_id: null, p_lease_token: initialRetryLease,
    })
    assert(!simulatedProfileFailure.error, `could not set up profile-retry scenario: ${simulatedProfileFailure.error?.message ?? 'unknown'}`)
    const resumedRetry = await reserve(retryId, 'school_user_create', schoolA.adminAuthId, retryPayload)
    assert(resumedRetry.state === 'identity_created' && resumedRetry.auth_user_id === retryAuthId,
      'profile retry did not resume from the already linked Auth identity')

    const adminA = await signIn(schoolA.adminEmail)
    const adminB = await signIn(schoolB.adminEmail)
    const contextA = await adminA.rpc('get_my_auth_context')
    const contextB = await adminB.rpc('get_my_auth_context')
    assert(!contextA.error && contextA.data?.profile?.school_id === schoolA.id, 'School A Auth subject did not resolve to its provisioned profile/school')
    assert(!contextB.error && contextB.data?.profile?.school_id === schoolB.id, 'School B Auth subject did not resolve to its provisioned profile/school')
    assert(contextA.data.profile.user_type === 'admin' && contextB.data.profile.user_type === 'admin', 'school administrator role mapping mismatch')
    assert(contextA.data.permissions.includes('school.manage'), 'school administrator permission context missing school.manage')

    const [visibleA, visibleB] = await Promise.all([
      adminA.from('staff_records').select('user_id,school_id'),
      adminB.from('staff_records').select('user_id,school_id'),
    ])
    assert(!visibleA.error && visibleA.data.every(row => row.school_id === schoolA.id), 'School A RLS returned a foreign school staff row')
    assert(!visibleB.error && visibleB.data.every(row => row.school_id === schoolB.id), 'School B RLS returned a foreign school staff row')
    assert(visibleA.data.some(row => row.user_id === teacherA.profileId) && !visibleA.data.some(row => row.user_id === teacherB.profileId), 'School A staff visibility failed isolation check')
    assert(visibleB.data.some(row => row.user_id === teacherB.profileId) && !visibleB.data.some(row => row.user_id === teacherA.profileId), 'School B staff visibility failed isolation check')

    const roleMutation = await adminA.from('users').update({ user_type: 'super_admin' }).eq('auth_user_id', schoolA.adminAuthId)
    assert(!!roleMutation.error, 'school administrator directly changed a profile role')
    const auditMutation = await adminA.from('audit_logs').insert({
      school_id: schoolB.id, actor_id: 1, action: 'ci.actor_impersonation',
    })
    assert(!!auditMutation.error, 'school administrator directly inserted an audit event for another school')

    // SQL function authorization independently rejects a mismatched actor and
    // the disallowed platform-owner role even when service_role invokes it.
    const deniedId = requestId()
    const deniedEmail = `ci-denied-${suffix}@example.test`
    const deniedAuth = await createIdentity(deniedEmail, deniedId)
    authIds.push(deniedAuth)
    const deniedPayload = {
      name: 'CI Forbidden Owner', email: deniedEmail, user_type: 'super_admin',
      username: `CI-FORBIDDEN-${suffix}`, gender: 'Other', phone: null,
      address: 'CI validation address', state_id: 1, lga_id: 1, nal_id: 1, emp_date: null,
    }
    const deniedRequest = await reserve(deniedId, 'school_user_create', schoolA.adminAuthId, deniedPayload)
    const deniedLease = await claimAndBind(deniedId, deniedAuth)
    const deniedRole = await service.rpc('provision_school_staff_profile', {
      p_request_id: deniedId, p_auth_user_id: deniedAuth, p_actor_auth_user_id: schoolA.adminAuthId,
      p_name: deniedPayload.name, p_user_type: deniedPayload.user_type, p_username: deniedPayload.username,
      p_gender: deniedPayload.gender, p_phone: null, p_address: deniedPayload.address,
      p_state_id: 1, p_lga_id: 1, p_nal_id: 1, p_emp_date: null, p_lease_token: deniedLease,
    })
    assert(!!deniedRole.error, 'school administrator provisioned a platform-owner role')
    assert(deniedRequest.operation === 'school_user_create', 'denial test request was not persisted as school-user operation')

    const mismatchId = requestId()
    const mismatchEmail = `ci-mismatch-${suffix}@example.test`
    const mismatchAuth = await createIdentity(mismatchEmail, mismatchId)
    authIds.push(mismatchAuth)
    const mismatchPayload = {
      name: 'CI Mismatch Teacher', email: mismatchEmail, user_type: 'teacher',
      username: `CI-MISMATCH-${suffix}`, gender: 'Other', phone: null,
      address: 'CI validation address', state_id: 1, lga_id: 1, nal_id: 1, emp_date: null,
    }
    const mismatchRequest = await reserve(mismatchId, 'school_user_create', schoolA.adminAuthId, mismatchPayload)
    const mismatchLease = await claimAndBind(mismatchId, mismatchAuth)
    const mismatch = await service.rpc('provision_school_staff_profile', {
      p_request_id: mismatchId, p_auth_user_id: mismatchAuth, p_actor_auth_user_id: schoolB.adminAuthId,
      p_name: mismatchPayload.name, p_user_type: mismatchPayload.user_type, p_username: mismatchPayload.username,
      p_gender: mismatchPayload.gender, p_phone: null, p_address: mismatchPayload.address,
      p_state_id: 1, p_lga_id: 1, p_nal_id: 1, p_emp_date: null, p_lease_token: mismatchLease,
    })
    assert(!!mismatch.error, 'SQL RPC accepted an actor ID different from the durable request actor')
    assert(mismatchRequest.actor_auth_user_id === schoolA.adminAuthId, 'mismatch request actor was not stored correctly')

    const unauthorizedReserve = await service.rpc('reserve_provisioning_request', {
      p_request_id: requestId(), p_operation: 'school_create', p_actor_auth_user_id: schoolA.adminAuthId,
      p_payload: { name: 'CI Unauthorized School' }, p_payload_hash: '0'.repeat(64),
    })
    assert(!!unauthorizedReserve.error, 'school administrator reserved a platform-level school creation request')

    // Missing/invalid JWT subject yields no provisioned context; bad passwords
    // are rejected by the local Auth service.
    const unprovisionedId = requestId()
    const unprovisionedEmail = `ci-unprovisioned-${suffix}@example.test`
    const unprovisioned = await createIdentity(unprovisionedEmail, unprovisionedId)
    authIds.push(unprovisioned)
    const unprovisionedClient = await signIn(unprovisionedEmail)
    const unprovisionedContext = await unprovisionedClient.rpc('get_my_auth_context')
    assert(!unprovisionedContext.error && unprovisionedContext.data === null, 'unprovisioned Auth account was granted an application context')
    const invalidClient = createClient(baseUrl!, anonKey!, { auth: { persistSession: false, autoRefreshToken: false } })
    const badPassword = await invalidClient.auth.signInWithPassword({ email: owner.email, password: 'invalid-password' })
    assert(!!badPassword.error, 'local Auth accepted an invalid password')

    const publicSignupEmail = `ci-public-signup-${suffix}@example.test`
    const signup = await invalidClient.auth.signUp({ email: publicSignupEmail, password })
    if (signup.data.user?.id) authIds.push(signup.data.user.id)
    const createdUsers = await service.auth.admin.listUsers({ page: 1, perPage: 1000 })
    assert(!createdUsers.error && !createdUsers.data.users.some(user => user.email === publicSignupEmail),
      'public Auth signup created an account despite signup being disabled')

    // Real SQL lease rejection and authorized recovery-state transition.
    const recoveryId = requestId()
    const recoveryEmail = `ci-recovery-${suffix}@example.test`
    const recoveryAuth = await createIdentity(recoveryEmail, recoveryId)
    authIds.push(recoveryAuth)
    await reserve(recoveryId, 'school_user_create', schoolA.adminAuthId, {
      name: 'CI Recovery Staff', email: recoveryEmail, user_type: 'teacher', username: `CI-RECOVERY-${suffix}`,
      gender: 'Other', phone: null, address: 'CI validation address', state_id: 1, lga_id: 1, nal_id: 1, emp_date: null,
    })
    const recoveryLease = await claimAndBind(recoveryId, recoveryAuth)
    const competing = await service.rpc('claim_provisioning_request', { p_request_id: recoveryId, p_lease_token: crypto.randomUUID() })
    assert(!!competing.error, 'competing worker unexpectedly acquired a live lease')
    const renewal = await service.rpc('renew_provisioning_lease', { p_request_id: recoveryId, p_lease_token: recoveryLease })
    assert(!renewal.error && renewal.data?.lease_expires_at, `lease renewal failed: ${renewal.error?.message ?? 'unknown'}`)
    const stale = await service.rpc('renew_provisioning_lease', { p_request_id: recoveryId, p_lease_token: crypto.randomUUID() })
    assert(!!stale.error, 'wrong worker renewed a live lease')
    const earlyCancel = await service.rpc('begin_provisioning_cancellation', {
      p_request_id: recoveryId, p_operator_auth_user_id: owner.authId,
    })
    assert(!!earlyCancel.error, 'cancellation proceeded while a worker held a live lease')
    const recoverable = await service.rpc('transition_provisioning_request', {
      p_request_id: recoveryId, p_next_state: 'failed_recoverable', p_error_code: 'ci_recovery_test',
      p_recovery_actor_auth_user_id: null, p_lease_token: recoveryLease,
    })
    assert(!recoverable.error, `recoverable transition failed: ${recoverable.error?.message ?? 'unknown'}`)
    const cancellation = await service.rpc('begin_provisioning_cancellation', {
      p_request_id: recoveryId, p_operator_auth_user_id: owner.authId,
    })
    assert(!cancellation.error, `authorized cancellation/recovery failed: ${cancellation.error?.message ?? 'unknown'}`)
    assert(cancellation.data?.auth_user_id === recoveryAuth, 'recovery did not return the Auth identity bound to the request')
    const deletedIdentity = await service.auth.admin.deleteUser(recoveryAuth)
    assert(!deletedIdentity.error, `CI Auth compensation failed: ${deletedIdentity.error?.message ?? 'unknown'}`)
    const identityAfterDelete = await service.auth.admin.getUserById(recoveryAuth)
    assert(identityAfterDelete.error?.status === 404, 'Auth compensation was not confirmed by reading actual Auth state')
    const finalized = await service.rpc('finish_provisioning_cancellation', {
      p_request_id: recoveryId, p_operator_auth_user_id: owner.authId, p_succeeded: true,
    })
    assert(!finalized.error && finalized.data?.state === 'failed_terminal', 'successful compensation did not produce terminal cancellation')

    // Check transactional audit binding from actual provisioning writes.
    const audit = await service.from('audit_logs').select('actor_id,school_id,action')
      .eq('action', 'school.created').eq('school_id', schoolA.id).maybeSingle()
    assert(!audit.error && audit.data?.actor_id, `school creation audit missing: ${audit.error?.message ?? 'unknown'}`)
    const ownerProfile = await service.from('users').select('id').eq('auth_user_id', owner.authId).single()
    assert(!ownerProfile.error && audit.data.actor_id === ownerProfile.data.id, 'audit trigger actor did not match the verified platform owner')

    const adminAProfile = await service.from('users').select('id').eq('auth_user_id', schoolA.adminAuthId).single()
    assert(!adminAProfile.error, 'could not resolve the School A profile for audit-trigger tests')
    const ownSchoolAudit = await auditInsertWithSubject(schoolA.adminAuthId, adminAProfile.data.id, schoolA.id, 'ci.audit.own-school')
    assert(ownSchoolAudit.success, 'audit trigger rejected an authenticated actor writing to their own school')
    const crossSchoolAudit = await auditInsertWithSubject(schoolA.adminAuthId, adminAProfile.data.id, schoolB.id, 'ci.audit.cross-school')
    assert(!crossSchoolAudit.success && crossSchoolAudit.error.includes('audit actor school does not match audit school'),
      'audit trigger did not reject a school administrator writing an audit event for a foreign school')
    const ownerCrossSchoolAudit = await auditInsertWithSubject(owner.authId, ownerProfile.data.id, schoolB.id, 'ci.audit.platform-owner')
    assert(ownerCrossSchoolAudit.success, 'platform owner audit behavior across school context changed unexpectedly')

    const impersonation = await service.from('audit_logs').insert({
      school_id: schoolA.id, actor_id: ownerProfile.data.id, action: 'ci.actor_impersonation',
    })
    assert(!!impersonation.error, 'service-role request without a transaction-bound subject impersonated an audit actor')
  } finally {
    // The entire Postgres/Auth stack is disposable. Cleanup is best-effort and
    // occurs only in this local CI project, never in hosted Supabase.
    for (const id of authIds) await service.auth.admin.deleteUser(id)
  }
})
