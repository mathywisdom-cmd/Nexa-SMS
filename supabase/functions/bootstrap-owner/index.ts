import { corsHeaders, getClients, jsonResponse, reserveProvisioningRequest, validRequestId, ProvisioningError } from '../_shared/http.ts'
import { runProvisioningRequest, safeProvisioningResponse } from '../_shared/provisioning.ts'

function tokenMatches(expected: string, supplied: string): boolean {
  const left = new TextEncoder().encode(expected)
  const right = new TextEncoder().encode(supplied)
  if (left.length !== right.length) return false
  let difference = 0
  for (let index = 0; index < left.length; index++) difference |= left[index] ^ right[index]
  return difference === 0
}

Deno.serve(async req => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: corsHeaders(req) })
  if (req.method !== 'POST') return jsonResponse({ error: 'method_not_allowed' }, 405, req)

  const expectedToken = Deno.env.get('NEXASMS_OWNER_BOOTSTRAP_TOKEN') ?? ''
  const suppliedToken = req.headers.get('x-bootstrap-token') ?? ''
  if (expectedToken.length < 32 || !tokenMatches(expectedToken, suppliedToken)) {
    return jsonResponse({ error: 'not_authorized' }, 403, req)
  }

  let requestId: string | null = null
  try {
    const input = await req.json() as { request_id?: unknown; email?: unknown; name?: unknown }
    requestId = typeof input.request_id === 'string' ? input.request_id : null
    const email = typeof input.email === 'string' ? input.email.trim().toLowerCase() : ''
    const name = typeof input.name === 'string' ? input.name.trim() : ''
    if (!validRequestId(requestId) || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 100 || name.length < 6 || name.length > 150) {
      return jsonResponse({ error: 'invalid_input' }, 400, req)
    }
    const clients = getClients()
    const service = clients.service
    const request = await reserveProvisioningRequest(service, requestId, 'owner_bootstrap', null, { email, name })
    if (request.state === 'failed_terminal' || request.state === 'cancelling') {
      return jsonResponse({ error: 'request_not_resumable', request_id: requestId, state: request.state }, 409, req)
    }
    const result = await runProvisioningRequest(service, request)
    return jsonResponse(safeProvisioningResponse(requestId, result), 200, req)
  } catch (error) {
    console.error('Owner bootstrap request failed', error instanceof ProvisioningError ? error.code : 'unknown')
    return jsonResponse({ error: 'provisioning_incomplete', request_id: requestId, recovery_required: !!requestId }, 503, req)
  }
})
