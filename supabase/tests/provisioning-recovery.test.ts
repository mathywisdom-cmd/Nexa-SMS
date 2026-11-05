import type { SupabaseClient } from 'npm:@supabase/supabase-js@2.119.0'
import {
  reserveProvisioningRequest,
  type ProvisioningRequest,
} from '../functions/_shared/http.ts'
import { runProvisioningRequest } from '../functions/_shared/provisioning.ts'
import { ProvisioningLease } from '../functions/_shared/provisioning.ts'
import { cancelProvisioningRequest } from '../functions/_shared/recovery.ts'

function assertEquals(actual: unknown, expected: unknown) {
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    throw new Error(`Expected ${JSON.stringify(expected)}, received ${JSON.stringify(actual)}`)
  }
}

async function assertRejects(operation: () => Promise<unknown>) {
  try {
    await operation()
  } catch {
    return
  }
  throw new Error('Expected operation to reject')
}

type FakeOptions = {
  inviteFailure?: boolean
  inviteTimeoutAfterCreate?: boolean
  mutateUserMetadataAfterInvite?: boolean
  omitServerMarkerAfterInvite?: boolean
  profileFailureCount?: number
  commitThenLoseResponse?: boolean
  deleteFailure?: boolean
  finishFailureCount?: number
  ownerRegistry?: { count: number }
  leaseDurationMs?: number
  renewFailureCount?: number
  inviteDelayMs?: number
}

function fakeService(options: FakeOptions = {}) {
  const authUsers = new Map<string, Record<string, unknown>>()
  const identityMap = new Map<string, string>()
  let request: Record<string, unknown> | null = null
  let identityIntent: { requestId: string; leaseToken: string } | null = null
  let nextUser = 1
  let profileCreates = 0
  let schoolCreates = 0
  let ownerCount = options.ownerRegistry?.count ?? 0
  let inviteFailure = options.inviteFailure ?? false
  let failureCount = options.profileFailureCount ?? 0
  let loseResponse = options.commitThenLoseResponse ?? false
  let deleteFailure = options.deleteFailure ?? false
  let finishFailureCount = options.finishFailureCount ?? 0
  let renewFailureCount = options.renewFailureCount ?? 0
  let renewalCount = 0
  const admin = {
    inviteUserByEmail: async (email: string) => {
      if (options.inviteDelayMs) await new Promise(resolve => setTimeout(resolve, options.inviteDelayMs))
      const exists = [...authUsers.values()].some(user => user.email === email)
      if (exists) return { data: { user: null }, error: { message: 'already registered' } }
      if (inviteFailure && !options.inviteTimeoutAfterCreate) {
        inviteFailure = false
        return { data: { user: null }, error: { message: 'invite unavailable' } }
      }
      const id = `auth-${nextUser++}`
      if (!identityIntent) return { data: { user: null }, error: { message: 'missing durable identity intent' } }
      const user = {
        id, email, user_metadata: {},
        app_metadata: options.omitServerMarkerAfterInvite
          ? {}
          : { nexasms_provisioning_request_id: identityIntent.requestId },
      }
      if (options.mutateUserMetadataAfterInvite) {
        user.user_metadata = { nexasms_provisioning_request_id: 'attacker-controlled-value' }
      }
      authUsers.set(id, user)
      if (!options.omitServerMarkerAfterInvite) identityMap.set(identityIntent.requestId, id)
      if (options.inviteTimeoutAfterCreate) return { data: { user: null }, error: { message: 'response timed out' } }
      return { data: { user }, error: null }
    },
    getUserById: async (id: string) => {
      const user = authUsers.get(id)
      return user ? { data: { user }, error: null } : { data: { user: null }, error: { status: 404 } }
    },
    deleteUser: async (id: string) => {
      if (deleteFailure) {
        deleteFailure = false
        return { error: { message: 'delete failed' } }
      }
      authUsers.delete(id)
      for (const [requestId, authId] of identityMap) if (authId === id) identityMap.delete(requestId)
      return { error: null }
    },
  }
  const service = {
    auth: { admin },
    rpc: async (name: string, args: Record<string, unknown>) => {
      if (name === 'reserve_provisioning_request') {
        if (request) {
          if (request.request_id !== args.p_request_id || request.payload_hash !== args.p_payload_hash
            || JSON.stringify(request.payload) !== JSON.stringify(args.p_payload)) {
            return { data: null, error: { message: 'idempotency conflict' } }
          }
          request.attempt_count = Number(request.attempt_count) + 1
          if (request.state === 'failed_recoverable') {
            request.state = request.auth_user_id ? 'identity_created' : 'pending'
          }
        } else {
          request = {
            request_id: args.p_request_id, operation: args.p_operation, actor_auth_user_id: args.p_actor_auth_user_id,
            payload: args.p_payload, payload_hash: args.p_payload_hash, state: 'pending', auth_user_id: null,
            profile_id: null, school_id: null, result: null, error_code: null, attempt_count: 1,
            lease_token: null, lease_expires_at: null,
          }
        }
        return { data: { ...request }, error: null }
      }
      if (!request) return { data: null, error: { message: 'missing request' } }
      if (name === 'record_provisioning_identity') {
        if (request.lease_token !== args.p_lease_token || Number(request.lease_expires_at) <= Date.now()
          || !['pending', 'identity_created'].includes(String(request.state))) {
          return { data: null, error: { message: 'lease not owned' } }
        }
        const identity = authUsers.get(String(args.p_auth_user_id))
        if ((identity?.app_metadata as Record<string, unknown> | undefined)?.nexasms_provisioning_request_id !== args.p_request_id) {
          return { data: null, error: { message: 'identity correlation mismatch' } }
        }
        if (identityMap.get(String(args.p_request_id)) !== args.p_auth_user_id) identityMap.set(String(args.p_request_id), String(args.p_auth_user_id))
        request.auth_user_id = args.p_auth_user_id
        request.state = 'identity_created'
        return { data: { state: request.state }, error: null }
      }
      if (name === 'renew_provisioning_lease') {
        if (renewFailureCount > 0) {
          renewFailureCount--
          return { data: null, error: { message: 'renewal failed' } }
        }
        if (!['pending', 'identity_created', 'profile_created'].includes(String(request.state))
          || request.state === 'cancelling'
          || request.lease_token !== args.p_lease_token
          || Number(request.lease_expires_at) <= Date.now()) {
          return { data: null, error: { message: 'lease not renewable' } }
        }
        request.lease_expires_at = Math.max(
          Date.now() + (options.leaseDurationMs ?? 10 * 60 * 1000),
          Number(request.lease_expires_at) + 1,
        )
        renewalCount++
        return { data: { lease_expires_at: new Date(Number(request.lease_expires_at)).toISOString() }, error: null }
      }
      if (name === 'begin_provisioning_identity_creation') {
        if (request.lease_token !== args.p_lease_token || Number(request.lease_expires_at) <= Date.now()
          || !['pending', 'identity_created'].includes(String(request.state))) {
          return { data: null, error: { message: 'lease not owned' } }
        }
        identityIntent = { requestId: String(args.p_request_id), leaseToken: String(args.p_lease_token) }
        return { data: { request_id: args.p_request_id, state: 'reserved' }, error: null }
      }
      if (name === 'claim_provisioning_request') {
        if (request.state === 'completed') return { data: { ...request }, error: null }
        if (!['pending', 'identity_created', 'profile_created'].includes(String(request.state))) {
          return { data: null, error: { message: 'not claimable' } }
        }
        if (Number(request.lease_expires_at) > Date.now() && request.lease_token !== args.p_lease_token) {
          return { data: null, error: { message: 'already claimed' } }
        }
        request.lease_token = args.p_lease_token
        request.lease_expires_at = Date.now() + (options.leaseDurationMs ?? 10 * 60 * 1000)
        request.attempt_count = Number(request.attempt_count) + 1
        return { data: { ...request }, error: null }
      }
      if (name === 'lookup_provisioning_identity') {
        const requestPayload = request?.payload as Record<string, unknown>
        const email = request?.operation === 'school_create' ? requestPayload.admin_email : requestPayload.email
        const mappedId = identityMap.get(String(args.p_request_id))
        const mappedUser = mappedId ? authUsers.get(mappedId) : null
        const id = mappedUser?.email === email ? mappedId : null
        const emailOccupied = [...authUsers.values()].some(user => user.email === email)
        return { data: { auth_user_id: id, email_occupied: emailOccupied && !id }, error: null }
      }
      if (name === 'get_provisioning_request') return { data: { ...request }, error: null }
      if (name === 'transition_provisioning_request') {
        if (args.p_recovery_actor_auth_user_id == null
          && (request.lease_token !== args.p_lease_token || Number(request.lease_expires_at) <= Date.now()
            || ['cancelling', 'failed_terminal', 'completed'].includes(String(request.state)))) {
          return { data: null, error: { message: 'lease not owned' } }
        }
        request.state = args.p_next_state
        request.error_code = args.p_error_code
        if (['failed_recoverable', 'failed_terminal', 'completed'].includes(String(request.state))) {
          request.lease_token = null
          request.lease_expires_at = null
        }
        return { data: { ...request }, error: null }
      }
      if (name === 'begin_provisioning_cancellation') {
        if (!['failed_recoverable', 'failed_terminal', 'cancelling'].includes(String(request.state))) {
          return { data: null, error: { message: 'not cancellable' } }
        }
        if (Number(request.lease_expires_at) > Date.now()) return { data: null, error: { message: 'worker active' } }
        request.state = 'cancelling'
        request.recovered_by = args.p_operator_auth_user_id
        const requestPayload = request.payload as Record<string, unknown>
        const expectedEmail = request.operation === 'school_create' ? requestPayload.admin_email : requestPayload.email
        return { data: { auth_user_id: request.auth_user_id, identity_email: expectedEmail ?? null }, error: null }
      }
      if (name === 'finish_provisioning_cancellation') {
        if (finishFailureCount > 0) {
          finishFailureCount--
          return { data: null, error: { message: 'response lost' } }
        }
        request.state = args.p_succeeded ? 'failed_terminal' : 'failed_recoverable'
        request.error_code = args.p_succeeded ? 'cancelled' : 'compensation_failed'
        if (args.p_succeeded) identityMap.delete(String(args.p_request_id))
        return { data: { state: request.state }, error: null }
      }
      if (name === 'provision_school_and_first_admin' || name === 'provision_school_staff_profile' || name === 'provision_platform_owner_profile') {
        if (!['identity_created', 'profile_created'].includes(String(request.state))
          || request.lease_token !== args.p_lease_token || Number(request.lease_expires_at) <= Date.now()) {
          return { data: null, error: { message: 'lease not owned' } }
        }
        if (request.state === 'profile_created' || request.state === 'completed') return { data: request.result, error: null }
        if (failureCount > 0) {
          failureCount--
          return { data: null, error: { message: 'profile insertion failed' } }
        }
        if (request.operation === 'owner_bootstrap' && (options.ownerRegistry?.count ?? ownerCount) > 0) {
          return { data: null, error: { message: 'platform owner is already provisioned' } }
        }
        const result = request.operation === 'school_create'
          ? { school_id: 200 + schoolCreates, admin_profile_id: 300 + profileCreates }
          : { profile_id: 300 + profileCreates }
        profileCreates++
        if (request.operation === 'school_create') schoolCreates++
        if (request.operation === 'owner_bootstrap') {
          ownerCount = (options.ownerRegistry?.count ?? ownerCount) + 1
          if (options.ownerRegistry) options.ownerRegistry.count = ownerCount
        }
        request.state = 'profile_created'
        request.profile_id = result.profile_id ?? result.admin_profile_id
        request.school_id = result.school_id ?? null
        request.result = result
        if (loseResponse) {
          loseResponse = false
          return { data: null, error: { message: 'response lost after transaction commit' } }
        }
        return { data: request.operation === 'school_create' ? [result] : result.profile_id, error: null }
      }
      return { data: null, error: { message: `unexpected RPC ${name}` } }
    },
  }
  return {
    service: service as unknown as SupabaseClient,
    authUsers,
    identityMap,
    get request() { return request as Record<string, unknown> | null },
    get counts() { return { identities: authUsers.size, profiles: profileCreates, schools: schoolCreates, owners: ownerCount } },
    get renewals() { return renewalCount },
  }
}

const requestId = '10000000-0000-4000-8000-000000000001'
const payload = { name: 'Smoke School', code: 'SMOKE', admin_name: 'School Admin', admin_email: 'admin@example.test', email: null, timezone: 'Africa/Kampala', currency: 'UGX', status: 'active' }

async function reserve(fake: ReturnType<typeof fakeService>, id = requestId, data = payload) {
  return reserveProvisioningRequest(fake.service, id, 'school_create', 'actor-auth-id', data)
}

async function markRecoverable(fake: ReturnType<typeof fakeService>, error = 'profile_write_failed') {
  if (fake.request) {
    fake.request.state = 'failed_recoverable'
    fake.request.error_code = error
    fake.request.lease_token = null
    fake.request.lease_expires_at = null
  }
}

Deno.test('invite failure remains retryable and creates only one school/profile', async () => {
  const fake = fakeService({ inviteFailure: true })
  const first = await reserve(fake)
  await assertRejects(() => runProvisioningRequest(fake.service, first))
  await markRecoverable(fake, 'auth_invite_failed')
  const retried = await reserve(fake)
  await runProvisioningRequest(fake.service, retried)
  assertEquals(fake.counts, { identities: 1, profiles: 1, schools: 1, owners: 0 })
})

Deno.test('Auth invite created identity but lost response reconciles after user metadata mutation', async () => {
  const fake = fakeService({ inviteTimeoutAfterCreate: true, mutateUserMetadataAfterInvite: true })
  const req = await reserve(fake)
  await runProvisioningRequest(fake.service, req)
  assertEquals(fake.counts, { identities: 1, profiles: 1, schools: 1, owners: 0 })
  assertEquals(fake.request?.state, 'completed')
})

Deno.test('missing server-controlled Auth marker is rejected without creating a profile', async () => {
  const fake = fakeService({ omitServerMarkerAfterInvite: true })
  const req = await reserve(fake)
  await assertRejects(() => runProvisioningRequest(fake.service, req))
  assertEquals(fake.counts.profiles, 0)
  assertEquals(fake.request?.state, 'failed_terminal')
})

Deno.test('profile insertion failure resumes the linked Auth identity without duplicates', async () => {
  const fake = fakeService({ profileFailureCount: 1 })
  const req = await reserve(fake)
  await assertRejects(() => runProvisioningRequest(fake.service, req))
  await markRecoverable(fake)
  await runProvisioningRequest(fake.service, await reserve(fake))
  assertEquals(fake.counts, { identities: 1, profiles: 1, schools: 1, owners: 0 })
})

Deno.test('committed SQL transaction with lost response returns durable result', async () => {
  const fake = fakeService({ commitThenLoseResponse: true })
  const result = await runProvisioningRequest(fake.service, await reserve(fake))
  assertEquals(result, { school_id: 200, admin_profile_id: 300 })
  assertEquals(fake.counts, { identities: 1, profiles: 1, schools: 1, owners: 0 })
  assertEquals(fake.request?.state, 'completed')
})

Deno.test('same idempotency key is reused; conflicting payload is rejected', async () => {
  const fake = fakeService()
  await reserve(fake)
  await reserve(fake)
  await assertRejects(() => reserve(fake, requestId, { ...payload, name: 'Different School' }))
  assertEquals(fake.counts.schools, 0)
})

Deno.test('concurrent identical requests yield one Auth identity and one school/profile', async () => {
  const fake = fakeService()
  const [left, right] = await Promise.all([reserve(fake), reserve(fake)])
  const outcomes = await Promise.allSettled([
    runProvisioningRequest(fake.service, left), runProvisioningRequest(fake.service, right),
  ])
  assertEquals(outcomes.filter(outcome => outcome.status === 'fulfilled').length, 1)
  assertEquals(outcomes.filter(outcome => outcome.status === 'rejected').length, 1)
  assertEquals(fake.counts, { identities: 1, profiles: 1, schools: 1, owners: 0 })
  const retry = await reserve(fake)
  await runProvisioningRequest(fake.service, retry)
  assertEquals(fake.counts, { identities: 1, profiles: 1, schools: 1, owners: 0 })
})

Deno.test('different owner bootstrap requests cannot create two owners', async () => {
  const ownerRegistry = { count: 0 }
  const fake = fakeService({ ownerRegistry })
  const ownerPayload = { email: 'owner@example.test', name: 'Platform Owner' }
  const first = await reserveProvisioningRequest(fake.service, requestId, 'owner_bootstrap', null, ownerPayload)
  const secondId = '20000000-0000-4000-8000-000000000002'
  const secondFake = fakeService({ ownerRegistry })
  const second = await reserveProvisioningRequest(secondFake.service, secondId, 'owner_bootstrap', null, { email: 'second@example.test', name: 'Second Owner' })
  const outcomes = await Promise.allSettled([
    runProvisioningRequest(fake.service, first),
    runProvisioningRequest(secondFake.service, second),
  ])
  assertEquals(outcomes.filter(outcome => outcome.status === 'fulfilled').length, 1)
  assertEquals(outcomes.filter(outcome => outcome.status === 'rejected').length, 1)
  assertEquals(ownerRegistry.count, 1)
})

Deno.test('pre-existing unmarked Auth identity is not adopted as a new user', async () => {
  const fake = fakeService()
  fake.authUsers.set('unrelated-account', {
    id: 'unrelated-account', email: payload.admin_email, user_metadata: { name: 'Existing person' },
  })
  const req = await reserve(fake)
  await assertRejects(() => runProvisioningRequest(fake.service, req))
  assertEquals(fake.counts.profiles, 0)
  assertEquals(fake.request?.state, 'failed_terminal')
})

Deno.test('failed Auth compensation is recorded as retryable rather than deleting the profile', async () => {
  const fake = fakeService({ deleteFailure: true })
  const userId = 'auth-orphan'
  const req = await reserve(fake)
  fake.authUsers.set(userId, { id: userId, email: payload.admin_email, user_metadata: {}, app_metadata: { nexasms_provisioning_request_id: requestId } })
  fake.identityMap.set(requestId, userId)
  fake.request!.auth_user_id = userId
  await markRecoverable(fake)
  const failed = await cancelProvisioningRequest(fake.service, requestId, 'operator-auth-id')
  assertEquals(failed.cancelled, false)
  assertEquals(fake.request?.state, 'failed_recoverable')
  assertEquals(fake.counts.profiles, 0)
  const retried = await cancelProvisioningRequest(fake.service, requestId, 'operator-auth-id')
  assertEquals(retried.cancelled, true)
  assertEquals(fake.request?.state, 'failed_terminal')
})

Deno.test('cancellation retry reconciles Auth deletion after response loss', async () => {
  const fake = fakeService({ finishFailureCount: 1 })
  const req = await reserve(fake)
  const userId = 'auth-to-delete'
  fake.authUsers.set(userId, { id: userId, email: payload.admin_email, user_metadata: {}, app_metadata: { nexasms_provisioning_request_id: requestId } })
  fake.identityMap.set(requestId, userId)
  fake.request!.auth_user_id = userId
  await markRecoverable(fake)
  await assertRejects(() => cancelProvisioningRequest(fake.service, requestId, 'operator-auth-id'))
  assertEquals(fake.request?.state, 'cancelling')
  assertEquals(fake.counts.identities, 0)
  const retry = await cancelProvisioningRequest(fake.service, requestId, 'operator-auth-id')
  assertEquals(retry.cancelled, true)
  assertEquals(fake.request?.state, 'failed_terminal')
})

Deno.test('orphan Auth identity lookup requires the server-side request map', async () => {
  const fake = fakeService()
  await reserve(fake)
  fake.authUsers.set('unrelated', { id: 'unrelated', email: payload.admin_email, user_metadata: { nexasms_provisioning_request_id: 'another-request' } })
  const { data } = await fake.service.rpc('lookup_provisioning_identity', { p_request_id: requestId })
  assertEquals(data, { auth_user_id: null, email_occupied: true })
})

Deno.test('lease renewal extends the live lease for its owning worker', async () => {
  const fake = fakeService({ leaseDurationMs: 60_000 })
  const req = await reserve(fake)
  const token = crypto.randomUUID()
  const claim = await fake.service.rpc('claim_provisioning_request', { p_request_id: req.request_id, p_lease_token: token })
  if (claim.error) throw new Error('claim failed')
  const previousExpiry = Number(fake.request?.lease_expires_at)
  const lease = new ProvisioningLease(fake.service, req.request_id, token, 60_000)
  await lease.renew()
  assertEquals(fake.renewals, 1)
  if (Number(fake.request?.lease_expires_at) <= previousExpiry) throw new Error('lease expiry did not advance')
})

Deno.test('expired lease cannot be renewed', async () => {
  const fake = fakeService()
  const req = await reserve(fake)
  const token = crypto.randomUUID()
  await fake.service.rpc('claim_provisioning_request', { p_request_id: req.request_id, p_lease_token: token })
  fake.request!.lease_expires_at = Date.now() - 1
  const renewed = await fake.service.rpc('renew_provisioning_lease', { p_request_id: req.request_id, p_lease_token: token })
  assertEquals(renewed.error !== null, true)
})

Deno.test('competing worker cannot renew or claim until prior lease expires', async () => {
  const fake = fakeService()
  const req = await reserve(fake)
  const first = crypto.randomUUID()
  const second = crypto.randomUUID()
  await fake.service.rpc('claim_provisioning_request', { p_request_id: req.request_id, p_lease_token: first })
  const competingClaim = await fake.service.rpc('claim_provisioning_request', { p_request_id: req.request_id, p_lease_token: second })
  assertEquals(competingClaim.error !== null, true)
  const competingRenewal = await fake.service.rpc('renew_provisioning_lease', { p_request_id: req.request_id, p_lease_token: second })
  assertEquals(competingRenewal.error !== null, true)
  fake.request!.lease_expires_at = Date.now() - 1
  const recoveredClaim = await fake.service.rpc('claim_provisioning_request', { p_request_id: req.request_id, p_lease_token: second })
  assertEquals(recoveredClaim.error, null)
  const staleRenewal = await fake.service.rpc('renew_provisioning_lease', { p_request_id: req.request_id, p_lease_token: first })
  assertEquals(staleRenewal.error !== null, true)
  const currentRenewal = await fake.service.rpc('renew_provisioning_lease', { p_request_id: req.request_id, p_lease_token: second })
  assertEquals(currentRenewal.error, null)
})

Deno.test('wrong worker identity cannot renew another worker lease', async () => {
  const fake = fakeService()
  const req = await reserve(fake)
  const owner = crypto.randomUUID()
  await fake.service.rpc('claim_provisioning_request', { p_request_id: req.request_id, p_lease_token: owner })
  const wrongWorker = await fake.service.rpc('renew_provisioning_lease', {
    p_request_id: req.request_id, p_lease_token: crypto.randomUUID(),
  })
  assertEquals(wrongWorker.error !== null, true)
})

Deno.test('lease renewal rejects cancellation and terminal states', async () => {
  const fake = fakeService()
  const req = await reserve(fake)
  const token = crypto.randomUUID()
  await fake.service.rpc('claim_provisioning_request', { p_request_id: req.request_id, p_lease_token: token })
  fake.request!.state = 'cancelling'
  const cancelling = await fake.service.rpc('renew_provisioning_lease', { p_request_id: req.request_id, p_lease_token: token })
  assertEquals(cancelling.error !== null, true)
  fake.request!.state = 'failed_terminal'
  const terminal = await fake.service.rpc('renew_provisioning_lease', { p_request_id: req.request_id, p_lease_token: token })
  assertEquals(terminal.error !== null, true)
  fake.request!.state = 'completed'
  const completed = await fake.service.rpc('renew_provisioning_lease', { p_request_id: req.request_id, p_lease_token: token })
  assertEquals(completed.error !== null, true)
})

Deno.test('renewal failure stops provisioning and retry recovers one Auth identity', async () => {
  const fake = fakeService({ renewFailureCount: 1, inviteDelayMs: 30 })
  const req = await reserve(fake)
  await assertRejects(() => runProvisioningRequest(fake.service, req, { leaseRenewalIntervalMs: 5 }))
  assertEquals(fake.request?.state, 'failed_recoverable')
  assertEquals(fake.counts.profiles, 0)
  assertEquals(fake.counts.identities, 1)
  const retry = await reserve(fake)
  await runProvisioningRequest(fake.service, retry, { leaseRenewalIntervalMs: 5 })
  assertEquals(fake.counts, { identities: 1, profiles: 1, schools: 1, owners: 0 })
})
