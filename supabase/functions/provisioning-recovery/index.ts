import { authenticateCaller, corsHeaders, getClients, hasPermission, jsonResponse, profileFrom, readProvisioningRequest, changeProvisioningState, ProvisioningError, validRequestId } from '../_shared/http.ts'
import { runProvisioningRequest, safeProvisioningResponse } from '../_shared/provisioning.ts'
import { cancelProvisioningRequest } from '../_shared/recovery.ts'

function tokenMatches(expected: string, supplied: string): boolean {
  const left = new TextEncoder().encode(expected)
  const right = new TextEncoder().encode(supplied)
  if (left.length < 32 || left.length !== right.length) return false
  let difference = 0
  for (let index = 0; index < left.length; index++) difference |= left[index] ^ right[index]
  return difference === 0
}

Deno.serve(async req => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: corsHeaders(req) })
  if (req.method !== 'POST') return jsonResponse({ error: 'method_not_allowed' }, 405, req)

  try {
    const input = await req.json() as { request_id?: unknown; action?: unknown }
    const requestId = typeof input.request_id === 'string' ? input.request_id : null
    const action = input.action
    if (!validRequestId(requestId) || !['status', 'resume', 'cancel'].includes(String(action))) {
      return jsonResponse({ error: 'invalid_input' }, 400, req)
    }

    let service: ReturnType<typeof getClients>['service']
    let userId: string | null = null
    let bootstrapOperator = false
    if (req.headers.has('authorization')) {
      const caller = await authenticateCaller(req)
      service = caller.service
      userId = caller.userId
      const profile = profileFrom(caller.context)
      if (profile?.user_type !== 'super_admin' || profile.school_id !== null
        || !hasPermission(caller.context, 'platform.schools.manage')) {
        return jsonResponse({ error: 'forbidden' }, 403, req)
      }
    } else {
      const expected = Deno.env.get('NEXASMS_PROVISIONING_OPERATOR_TOKEN') ?? ''
      const supplied = req.headers.get('x-provisioning-operator-token') ?? ''
      if (!tokenMatches(expected, supplied)) return jsonResponse({ error: 'unauthorized' }, 401, req)
      service = getClients().service
      bootstrapOperator = true
    }

    const request = await readProvisioningRequest(service, requestId)
    if (!request) return jsonResponse({ error: 'request_not_found' }, 404, req)
    if (bootstrapOperator && request.operation !== 'owner_bootstrap') {
      return jsonResponse({ error: 'forbidden' }, 403, req)
    }

    if (action === 'status') {
      return jsonResponse({
        request_id: request.request_id,
        operation: request.operation,
        state: request.state,
        profile_id: request.profile_id,
        school_id: request.school_id,
        error_code: request.error_code,
        attempt_count: request.attempt_count,
        created_at: request.created_at,
        updated_at: request.updated_at,
        recovered_at: request.recovered_at,
        cancelled_at: request.cancelled_at,
      }, 200, req)
    }

    if (action === 'resume') {
      if (request.state === 'failed_terminal' || request.state === 'cancelling') {
        return jsonResponse({ error: 'request_not_resumable', request_id: requestId, state: request.state }, 409, req)
      }
      if (bootstrapOperator) {
        const { error } = await service.rpc('resume_owner_bootstrap_provisioning', { p_request_id: requestId })
        if (error) return jsonResponse({ error: 'request_not_resumable', request_id: requestId }, 409, req)
      } else {
        if (request.state === 'failed_recoverable') {
          await changeProvisioningState(service, requestId, 'pending', null, userId)
        } else {
          await changeProvisioningState(service, requestId, request.state, null, userId)
        }
      }
      const refreshed = await readProvisioningRequest(service, requestId)
      if (!refreshed) return jsonResponse({ error: 'request_not_found' }, 404, req)
      const result = await runProvisioningRequest(service, refreshed)
      return jsonResponse(safeProvisioningResponse(requestId, result), 200, req)
    }

    try {
      const cancellation = await cancelProvisioningRequest(service, requestId, userId)
      return jsonResponse(cancellation, cancellation.cancelled ? 200 : 503, req)
    } catch (error) {
      if (error instanceof ProvisioningError && error.code === 'request_not_cancellable') {
        return jsonResponse({ error: error.code, request_id: requestId }, 409, req)
      }
      return jsonResponse({ error: 'cancellation_recovery_required', request_id: requestId }, 503, req)
    }
  } catch (error) {
    console.error('Provisioning recovery failed', error instanceof ProvisioningError ? error.code : 'unknown')
    const message = error instanceof Error ? error.message : ''
    return jsonResponse({ error: message === 'unauthorized' ? 'unauthorized' : message === 'unprovisioned' ? 'unprovisioned' : 'recovery_unavailable' }, message === 'unauthorized' ? 401 : message === 'unprovisioned' ? 403 : 503, req)
  }
})
