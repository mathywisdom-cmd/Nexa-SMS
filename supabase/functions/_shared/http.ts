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

export class RequestBodyError extends Error {
  constructor(public code: 'payload_too_large' | 'missing_body' | 'invalid_content_type' | 'invalid_json' | 'invalid_body', public status: 400 | 413) {
    super(code)
  }
}

/** Read bounded JSON without trusting Content-Length as the only limit. */
export async function readJsonObject(req: Request, maxBytes: number): Promise<Record<string, unknown>> {
  if (!/^application\/json(?:\s*;|$)/i.test(req.headers.get('content-type') ?? '')) {
    throw new RequestBodyError('invalid_content_type', 400)
  }
  const declaredLength = req.headers.get('content-length')
  if (declaredLength && /^\d+$/.test(declaredLength) && Number(declaredLength) > maxBytes) {
    await req.body?.cancel().catch(() => undefined)
    throw new RequestBodyError('payload_too_large', 413)
  }
  if (!req.body) throw new RequestBodyError('missing_body', 400)

  const reader = req.body.getReader()
  const chunks: Uint8Array[] = []
  let size = 0
  try {
    while (true) {
      const { done, value } = await reader.read()
      if (done) break
      size += value.byteLength
      if (size > maxBytes) {
        await reader.cancel().catch(() => undefined)
        throw new RequestBodyError('payload_too_large', 413)
      }
      chunks.push(value)
    }
  } catch (error) {
    if (error instanceof RequestBodyError) throw error
    throw new RequestBodyError('invalid_body', 400)
  } finally {
    reader.releaseLock()
  }
  if (size === 0) throw new RequestBodyError('missing_body', 400)

  const bytes = new Uint8Array(size)
  let offset = 0
  for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength }
  let value: unknown
  try { value = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes)) } catch {
    throw new RequestBodyError('invalid_json', 400)
  }
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new RequestBodyError('invalid_body', 400)
  return value as Record<string, unknown>
}

export function requestBodyErrorResponse(error: unknown, req: Request): Response | null {
  if (!(error instanceof RequestBodyError)) return null
  return jsonResponse({ error: error.code }, error.status, req)
}

export async function sha256Hex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('')
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

export async function consumeOwnerBootstrapRateLimit(service: SupabaseClient, sourceHash: string): Promise<boolean> {
  const { data, error } = await service.rpc('consume_owner_bootstrap_rate_limit', { p_source_hash: sourceHash })
  if (error || typeof data !== 'boolean') throw new ProvisioningError('bootstrap_rate_limit_unavailable')
  return data
}

export async function reserveOwnerBootstrapRequest(
  service: SupabaseClient,
  requestId: string,
  payload: Record<string, unknown>,
  tokenHash: string,
  sourceHash: string,
): Promise<ProvisioningRequest> {
  const payloadHash = await hashProvisioningPayload(payload)
  const { data, error } = await service.rpc('reserve_owner_bootstrap_request', {
    p_request_id: requestId,
    p_payload: payload,
    p_payload_hash: payloadHash,
    p_token_hash: tokenHash,
    p_source_hash: sourceHash,
  })
  if (error || !data || data.ok !== true || !data.request) {
    const code = typeof data?.code === 'string' ? data.code : 'bootstrap_unavailable'
    throw new ProvisioningError(code, ['owner_already_exists', 'request_conflict', 'bootstrap_replayed', 'bootstrap_expired', 'not_authorized'].includes(code))
  }
  return { ...data.request, operation: 'owner_bootstrap', actor_auth_user_id: null, payload, payload_hash: payloadHash } as ProvisioningRequest
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

export async function provisioningIdentity(service: SupabaseClient, requestId: string, email: string) {
  const { data: lookup, error: lookupError } = await service.rpc('lookup_provisioning_identity', {
    p_request_id: requestId,
  })
  if (lookupError || !lookup) throw new ProvisioningError('auth_lookup_failed')
  const recoveredId = typeof lookup.auth_user_id === 'string' ? lookup.auth_user_id : null
  if (recoveredId) {
    const { data: recovered, error: recoveredError } = await service.auth.admin.getUserById(recoveredId)
    if (recoveredError || !recovered.user
      || recovered.user.email?.toLowerCase() !== email.toLowerCase()) {
      throw new ProvisioningError('auth_lookup_failed')
    }
    return recovered.user
  }
  if (lookup.email_occupied === true) throw new ProvisioningError('identity_conflict', true)
  return null
}

export async function inviteIdentity(service: SupabaseClient, email: string, requestId: string) {
  // A before-insert Auth trigger atomically binds this invite to the reserved
  // server-side intent. User-editable metadata is deliberately not involved.
  const { data, error } = await service.auth.admin.inviteUserByEmail(email)
  if (!error && data.user) {
    const bound = await provisioningIdentity(service, requestId, email)
    if (!bound || bound.id !== data.user.id) throw new ProvisioningError('identity_correlation_failed', true)
    return bound
  }

  // A request timeout may follow a committed Auth insert. Only the private
  // database mapping can reconcile it; an occupied email without that mapping
  // is a conflict and must never be adopted.
  const recovered = await provisioningIdentity(service, requestId, email)
  if (recovered) return recovered
  throw new ProvisioningError('auth_invite_failed')
}

export async function beginProvisioningIdentityCreation(service: SupabaseClient, requestId: string, leaseToken: string) {
  const { error } = await service.rpc('begin_provisioning_identity_creation', {
    p_request_id: requestId,
    p_lease_token: leaseToken,
  })
  if (error) throw new ProvisioningError('identity_intent_failed')
}

export async function removeIdentity(service: SupabaseClient, userId: string) {
  const { error } = await service.auth.admin.deleteUser(userId)
  if (error) throw new ProvisioningError('compensation_failed')
}
