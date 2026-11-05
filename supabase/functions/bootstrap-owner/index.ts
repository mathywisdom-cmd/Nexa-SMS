import { consumeOwnerBootstrapRateLimit, corsHeaders, getClients, jsonResponse, readJsonObject, requestBodyErrorResponse, reserveOwnerBootstrapRequest, sha256Hex, validRequestId, ProvisioningError } from '../_shared/http.ts'
import { expiringTokenMatches } from '../_shared/operator-token.ts'
import { runProvisioningRequest, safeProvisioningResponse } from '../_shared/provisioning.ts'

Deno.serve(async req => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: corsHeaders(req) })
  if (req.method !== 'POST') return jsonResponse({ error: 'method_not_allowed' }, 405, req)

  const expectedToken = Deno.env.get('NEXASMS_OWNER_BOOTSTRAP_TOKEN') ?? ''
  const expiresAtText = Deno.env.get('NEXASMS_OWNER_BOOTSTRAP_TOKEN_EXPIRES_AT')
  const expiresAt = Date.parse(expiresAtText ?? '')
  const suppliedToken = req.headers.get('x-bootstrap-token') ?? ''

  let requestId: string | null = null
  try {
    const clients = getClients()
    const sourceAddress = req.headers.get('cf-connecting-ip')
    if (!sourceAddress) return jsonResponse({ error: 'temporarily_unavailable' }, 503, req)
    const sourceHash = await sha256Hex(sourceAddress)
    if (!await consumeOwnerBootstrapRateLimit(clients.service, sourceHash)) {
      return jsonResponse({ error: 'temporarily_unavailable' }, 429, req)
    }
    if (Number.isFinite(expiresAt) && expiresAt <= Date.now()) {
      await clients.service.rpc('record_owner_bootstrap_security_event', {
        p_source_hash: sourceHash, p_outcome: 'credential_expired', p_request_id: null,
      })
      return jsonResponse({ error: 'not_authorized' }, 403, req)
    }
    if (!expiringTokenMatches(expectedToken, suppliedToken, expiresAtText)) {
      await clients.service.rpc('record_owner_bootstrap_security_event', {
        p_source_hash: sourceHash, p_outcome: 'credential_rejected', p_request_id: null,
      })
      return jsonResponse({ error: 'not_authorized' }, 403, req)
    }

    const input = await readJsonObject(req, 8 * 1024) as { request_id?: unknown; email?: unknown; name?: unknown }
    requestId = typeof input.request_id === 'string' ? input.request_id : null
    const email = typeof input.email === 'string' ? input.email.trim().toLowerCase() : ''
    const name = typeof input.name === 'string' ? input.name.trim() : ''
    if (!validRequestId(requestId) || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 100 || name.length < 6 || name.length > 150) {
      return jsonResponse({ error: 'invalid_input' }, 400, req)
    }
    const service = clients.service
    const payload = { email, name }
    const tokenHash = await sha256Hex(suppliedToken)
    const request = await reserveOwnerBootstrapRequest(service, requestId, payload, tokenHash, sourceHash)
    if (request.state === 'failed_terminal' || request.state === 'cancelling') {
      return jsonResponse({ error: 'request_not_resumable', request_id: requestId, state: request.state }, 409, req)
    }
    const result = await runProvisioningRequest(service, request)
    return jsonResponse(safeProvisioningResponse(requestId, result), 200, req)
  } catch (error) {
    const bodyError = requestBodyErrorResponse(error, req)
    if (bodyError) return bodyError
    const code = error instanceof ProvisioningError ? error.code : 'unknown'
    console.error('Owner bootstrap request failed', code)
    if (['not_authorized', 'bootstrap_expired'].includes(code)) return jsonResponse({ error: 'not_authorized' }, 403, req)
    if (error instanceof ProvisioningError && error.terminal) return jsonResponse({ error: 'request_not_resumable' }, 409, req)
    return jsonResponse({ error: 'provisioning_incomplete', request_id: requestId, recovery_required: !!requestId }, 503, req)
  }
})
