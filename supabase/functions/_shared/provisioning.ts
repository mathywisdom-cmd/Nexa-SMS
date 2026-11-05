import type { SupabaseClient } from 'npm:@supabase/supabase-js@2.119.0'
import {
  changeProvisioningState,
  beginProvisioningIdentityCreation,
  inviteIdentity,
  ledgerErrorCode,
  ProvisioningError,
  readProvisioningRequest,
  provisioningIdentity,
  type ProvisioningRequest,
} from './http.ts'

function requestedEmail(request: ProvisioningRequest): string {
  const value = request.operation === 'school_create'
    ? request.payload.admin_email
    : request.payload.email
  if (typeof value !== 'string') throw new ProvisioningError('request_payload_invalid', true)
  return value.toLowerCase()
}

/**
 * A per-invocation UUID is the lease capability. Renewal never changes worker
 * identity: it must present that same token for the same durable request.
 */
export class ProvisioningLease {
  private timer: ReturnType<typeof setInterval> | null = null
  private renewal: Promise<void> | null = null
  private failure: ProvisioningError | null = null

  constructor(
    private readonly service: SupabaseClient,
    private readonly requestId: string,
    private readonly leaseToken: string,
    private readonly renewalIntervalMs = 120_000,
  ) {}

  start() {
    if (this.timer) return
    this.timer = setInterval(() => { void this.renew().catch(() => undefined) }, this.renewalIntervalMs)
  }

  assertOwned() {
    if (this.failure) throw this.failure
  }

  async renew(): Promise<void> {
    this.assertOwned()
    if (!this.renewal) {
      this.renewal = (async () => {
        try {
          const { data, error } = await this.service.rpc('renew_provisioning_lease', {
            p_request_id: this.requestId,
            p_lease_token: this.leaseToken,
          })
          if (error || !data || typeof data.lease_expires_at !== 'string') {
            this.failure = new ProvisioningError('lease_lost')
          }
        } catch {
          this.failure = new ProvisioningError('lease_lost')
        }
      })()
    }
    await this.renewal
    this.renewal = null
    this.assertOwned()
  }

  async stop() {
    if (this.timer) clearInterval(this.timer)
    this.timer = null
    if (this.renewal) await this.renewal
    this.assertOwned()
  }
}

async function ensureIdentity(
  service: SupabaseClient,
  request: ProvisioningRequest,
  lease: ProvisioningLease,
  leaseToken: string,
): Promise<string> {
  const email = requestedEmail(request)
  let authUserId = request.auth_user_id
  lease.assertOwned()
  if (authUserId) {
    const { data, error } = await service.auth.admin.getUserById(authUserId)
    lease.assertOwned()
    if (error && error.status !== 404) throw new ProvisioningError('auth_lookup_failed')
    if (data.user) {
      const bound = await provisioningIdentity(service, request.request_id, email)
      if (data.user.email?.toLowerCase() !== email || bound?.id !== authUserId) {
        throw new ProvisioningError('identity_marker_mismatch', true)
      }
      return authUserId
    }
    // The previous operation may have completed a deletion before timing out.
    // A profile must never be created against a missing Auth identity.
    if (error?.status !== 404) throw new ProvisioningError('auth_lookup_failed')
    authUserId = null
  }

  const alreadyBound = await provisioningIdentity(service, request.request_id, email)
  if (alreadyBound) authUserId = alreadyBound.id
  if (!authUserId) await beginProvisioningIdentityCreation(service, request.request_id, leaseToken)
  lease.assertOwned()
  if (!authUserId) {
    const user = await inviteIdentity(service, email, request.request_id)
    lease.assertOwned()
    authUserId = user.id
  }
  const { error } = await service.rpc('record_provisioning_identity', {
    p_request_id: request.request_id,
    p_auth_user_id: authUserId,
    p_lease_token: leaseToken,
  })
  if (error) throw new ProvisioningError('identity_record_failed')
  lease.assertOwned()
  return authUserId
}

export async function runProvisioningRequest(
  service: SupabaseClient,
  request: ProvisioningRequest,
  options: { leaseRenewalIntervalMs?: number } = {},
) {
  if (request.state === 'completed') return request.result
  if (request.state === 'failed_terminal' || request.state === 'cancelling') {
    throw new ProvisioningError('request_not_resumable', true)
  }
  const leaseToken = crypto.randomUUID()
  const { data: claimed, error: claimError } = await service.rpc('claim_provisioning_request', {
    p_request_id: request.request_id,
    p_lease_token: leaseToken,
  })
  if (claimError || !claimed) throw new ProvisioningError('request_in_progress')
  const current = { ...request, ...claimed } as ProvisioningRequest
  if (current.state === 'completed') return current.result

  const lease = new ProvisioningLease(service, current.request_id, leaseToken, options.leaseRenewalIntervalMs)
  lease.start()
  let leaseStopped = false
  const stopLease = async () => {
    if (leaseStopped) return
    await lease.stop()
    leaseStopped = true
  }

  try {
    if (current.state === 'profile_created') {
      await stopLease()
      await changeProvisioningState(service, current.request_id, 'completed', null, null, leaseToken)
      return current.result
    }

    const authUserId = await ensureIdentity(service, current, lease, leaseToken)
    lease.assertOwned()
    // Heartbeat covers the external Auth work. Stop it before the short atomic
    // profile RPC, which independently checks the same live token in SQL.
    await stopLease()
    let responseData: unknown
    let operationError: unknown

    if (current.operation === 'owner_bootstrap') {
      const { data, error } = await service.rpc('provision_platform_owner_profile', {
        p_request_id: current.request_id,
        p_auth_user_id: authUserId,
        p_name: current.payload.name,
        p_lease_token: leaseToken,
      })
      responseData = { profile_id: data }
      operationError = error
    } else if (current.operation === 'school_create') {
      const { data, error } = await service.rpc('provision_school_and_first_admin', {
        p_request_id: current.request_id,
        p_auth_user_id: authUserId,
        p_actor_auth_user_id: current.actor_auth_user_id,
        p_school_name: current.payload.name,
        p_school_code: current.payload.code,
        p_registration_number: current.payload.registration_number,
        p_school_email: current.payload.email,
        p_phone: current.payload.phone,
        p_address: current.payload.address,
        p_timezone: current.payload.timezone,
        p_currency: current.payload.currency,
        p_status: current.payload.status,
        p_admin_name: current.payload.admin_name,
        p_lease_token: leaseToken,
      })
      const row = Array.isArray(data) ? data[0] : data
      responseData = row ? { school_id: row.school_id, admin_profile_id: row.admin_profile_id } : null
      operationError = error
    } else {
      const { data, error } = await service.rpc('provision_school_staff_profile', {
        p_request_id: current.request_id,
        p_auth_user_id: authUserId,
        p_actor_auth_user_id: current.actor_auth_user_id,
        p_name: current.payload.name,
        p_user_type: current.payload.user_type,
        p_username: current.payload.username,
        p_gender: current.payload.gender,
        p_phone: current.payload.phone,
        p_address: current.payload.address,
        p_state_id: current.payload.state_id,
        p_lga_id: current.payload.lga_id,
        p_nal_id: current.payload.nal_id,
        p_emp_date: current.payload.emp_date,
        p_lease_token: leaseToken,
      })
      responseData = { profile_id: data }
      operationError = error
    }

    if (operationError) {
      const databaseError = operationError as { message?: string }
      if (/platform owner is already provisioned/i.test(databaseError.message ?? '')) {
        throw new ProvisioningError('owner_already_exists', true)
      }
      throw new ProvisioningError('profile_write_failed')
    }

    const persisted = await readProvisioningRequest(service, current.request_id)
    if (!persisted || !['profile_created', 'completed'].includes(persisted.state)) {
      throw new ProvisioningError('profile_write_failed')
    }
    if (persisted.state === 'profile_created') {
      await changeProvisioningState(service, current.request_id, 'completed', null, null, leaseToken)
    }
    return persisted.result ?? responseData
  } catch (error) {
    try { await stopLease() } catch { /* do not continue work after lease loss */ }
    // The transaction can commit even when PostgREST loses its response. The
    // ledger result wins over the HTTP error and is safe to return idempotently.
    try {
      const persisted = await readProvisioningRequest(service, current.request_id)
      if (persisted?.state === 'profile_created') {
        await changeProvisioningState(service, current.request_id, 'completed', null, null, leaseToken)
        return persisted.result
      }
      if (persisted?.state === 'completed') return persisted.result
      if (persisted && !['cancelling', 'failed_terminal'].includes(persisted.state)) {
        const terminal = error instanceof ProvisioningError && error.terminal
        await changeProvisioningState(service, current.request_id,
          terminal ? 'failed_terminal' : 'failed_recoverable', ledgerErrorCode(error), null, leaseToken)
      }
    } catch {
      // If this worker lost its lease, its token cannot transition or overwrite
      // the new owner's state. Leave the ledger for reconciliation/retry.
    }
    throw error
  } finally {
    try { await stopLease() } catch { /* durable recovery handles expired/lost leases */ }
  }
}

export function safeProvisioningResponse(requestId: string, result: unknown) {
  return { provisioned: true, request_id: requestId, result }
}
