import { createClient, type SupabaseClient } from 'npm:@supabase/supabase-js@2.119.0'

const defaultOrigins = ['https://nexa-smsv.vercel.app']

export function corsHeaders(req: Request) {
  const configured = (Deno.env.get('NEXASMS_ALLOWED_ORIGINS') ?? '').split(',').map(value => value.trim()).filter(Boolean)
  const origin = req.headers.get('origin') ?? ''
  // An explicit deployment allowlist replaces the production default, so a
  // production setting can remove local development origins instead of only
  // adding to them.
  const allowedOrigins = configured.length > 0 ? configured : defaultOrigins
  const allowed = allowedOrigins.includes(origin)
  return {
  'Access-Control-Allow-Headers': 'authorization, apikey, content-type, x-client-info, x-bootstrap-token, x-provisioning-operator-token, idempotency-key',
  'Access-Control-Allow-Methods': 'POST, OPTIONS',
    ...(allowed ? { 'Access-Control-Allow-Origin': origin } : {}),
    'Vary': 'Origin',
  }
}

export function jsonResponse(body: unknown, status = 200, req?: Request): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...(req ? corsHeaders(req) : {}), 'Content-Type': 'application/json', 'Cache-Control': 'no-store' },
  })
}

export function getClients() {
  const url = Deno.env.get('SUPABASE_URL')
  const anon = Deno.env.get('SUPABASE_ANON_KEY')
  const serviceKey = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')
  if (!url || !anon || !serviceKey) throw new Error('Supabase function environment is incomplete')
  return {
    url,
    anon,
    service: createClient(url, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } }),
  }
}

export async function authenticateCaller(req: Request): Promise<{
  service: SupabaseClient
  userId: string
  context: Record<string, unknown>
}> {
  const { url, anon, service } = getClients()
  const authorization = req.headers.get('authorization') ?? ''
  const match = authorization.match(/^Bearer\s+(.+)$/i)
  if (!match) throw new Error('unauthorized')
  const token = match[1]
  const userClient = createClient(url, anon, {
    auth: { persistSession: false, autoRefreshToken: false },
    global: { headers: { Authorization: `Bearer ${token}` } },
  })
  const { data: authData, error: authError } = await userClient.auth.getUser(token)
  if (authError || !authData.user) throw new Error('unauthorized')
  const { data: context, error: contextError } = await userClient.rpc('get_my_auth_context')
  if (contextError || !context || typeof context !== 'object') throw new Error('unprovisioned')
  return { service, userId: authData.user.id, context: context as Record<string, unknown> }
}

export function hasPermission(context: Record<string, unknown>, permission: string): boolean {
  const permissions = context.permissions
  return Array.isArray(permissions) && permissions.includes(permission)
}

export function profileFrom(context: Record<string, unknown>): Record<string, unknown> | null {
  const value = context.profile
  return value && typeof value === 'object' ? value as Record<string, unknown> : null
}

export type ProvisioningState = 'pending' | 'identity_created' | 'profile_created' | 'completed' | 'failed_recoverable' | 'failed_terminal' | 'cancelling'
export type ProvisioningOperation = 'owner_bootstrap' | 'school_create' | 'school_user_create'
export type ProvisioningRequest = {
  request_id: string
  operation: ProvisioningOperation
  actor_auth_user_id: string | null
  payload: Record<string, unknown>
  payload_hash: string
  state: ProvisioningState
  auth_user_id: string | null
  profile_id: number | null
  school_id: number | null
  result: Record<string, unknown> | null
  error_code: string | null
  attempt_count: number
  created_at?: string
  updated_at?: string
  recovered_at?: string | null
  cancelled_at?: string | null
  lease_expires_at?: string | null
}

export class ProvisioningError extends Error {
  constructor(public code: string, public terminal = false) {
    super(code)
  }
}

export function ledgerErrorCode(error: unknown): string {
  if (!(error instanceof ProvisioningError)) return 'recovery_required'
  if (error.code === 'auth_invite_failed') return 'auth_invite_failed'
  if (error.code === 'compensation_failed') return 'compensation_failed'
  if (error.code === 'owner_already_exists') return 'owner_already_exists'
  if (error.code === 'identity_conflict' || error.code === 'request_conflict') return 'request_conflict'
  if (error.code === 'profile_write_failed') return 'profile_write_failed'
  return 'recovery_required'
}

function stableValue(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(stableValue)
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => [key, stableValue(item)]))
  }
  return value
}

export async function hashProvisioningPayload(payload: Record<string, unknown>): Promise<string> {
  const bytes = new TextEncoder().encode(JSON.stringify(stableValue(payload)))
  const digest = await crypto.subtle.digest('SHA-256', bytes)
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('')
}

export function validRequestId(value: string | null): value is string {
  return !!value && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)
}

export async function reserveProvisioningRequest(
  service: SupabaseClient,
  requestId: string,
  operation: ProvisioningOperation,
  actorAuthUserId: string | null,
  payload: Record<string, unknown>,
): Promise<ProvisioningRequest> {
  const payloadHash = await hashProvisioningPayload(payload)
  const { data, error } = await service.rpc('reserve_provisioning_request', {
    p_request_id: requestId,
    p_operation: operation,
    p_actor_auth_user_id: actorAuthUserId,
    p_payload: payload,
    p_payload_hash: payloadHash,
  })
  if (error || !data) throw new ProvisioningError('request_conflict', true)
  return { ...data, operation, actor_auth_user_id: actorAuthUserId, payload, payload_hash: payloadHash } as ProvisioningRequest
}

export async function readProvisioningRequest(service: SupabaseClient, requestId: string): Promise<ProvisioningRequest | null> {
  const { data, error } = await service.rpc('get_provisioning_request', { p_request_id: requestId })
  if (error) throw new ProvisioningError('recovery_unavailable')
  return data as ProvisioningRequest | null
}

export async function changeProvisioningState(
  service: SupabaseClient,
  requestId: string,
  state: ProvisioningState,
  errorCode: string | null = null,
  recoveryActorAuthUserId: string | null = null,
  leaseToken: string | null = null,
) {
  const { data, error } = await service.rpc('transition_provisioning_request', {
    p_request_id: requestId,
    p_next_state: state,
    p_error_code: errorCode,
    p_recovery_actor_auth_user_id: recoveryActorAuthUserId,
    p_lease_token: leaseToken,
  })
  if (error || !data) throw new ProvisioningError('state_transition_failed')
  return data as Record<string, unknown>
}

export async function inviteIdentity(service: SupabaseClient, email: string, requestId: string) {
  // The user-metadata marker is correlation data only. It is never used for
  // authorization; SQL actor/permission checks remain authoritative.
  const { data, error } = await service.auth.admin.inviteUserByEmail(email, {
    data: { nexasms_provisioning_request_id: requestId },
  })
  if (!error && data.user) return data.user

  // HTTP failure can follow a successful Auth write. Resolve by exact email
  // and immutable request correlation in the private database, not by scanning
  // or exposing the Auth user directory.
  const { data: lookup, error: lookupError } = await service.rpc('lookup_provisioning_identity', {
    p_request_id: requestId,
  })
  if (lookupError || !lookup) throw new ProvisioningError('auth_lookup_failed')
  const recoveredId = typeof lookup.auth_user_id === 'string' ? lookup.auth_user_id : null
  if (recoveredId) {
    const { data: recovered, error: recoveredError } = await service.auth.admin.getUserById(recoveredId)
    if (recoveredError || !recovered.user
      || recovered.user.email?.toLowerCase() !== email.toLowerCase()
      || recovered.user.user_metadata?.nexasms_provisioning_request_id !== requestId) {
      throw new ProvisioningError('auth_lookup_failed')
    }
    return recovered.user
  }
  if (lookup.email_occupied === true) throw new ProvisioningError('identity_conflict', true)
  throw new ProvisioningError('auth_invite_failed')
}

export async function removeIdentity(service: SupabaseClient, userId: string) {
  const { error } = await service.auth.admin.deleteUser(userId)
  if (error) throw new ProvisioningError('compensation_failed')
}
