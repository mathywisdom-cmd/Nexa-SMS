import { authenticateCaller, corsHeaders, hasPermission, jsonResponse, profileFrom, reserveProvisioningRequest, validRequestId, ProvisioningError } from '../_shared/http.ts'
import { runProvisioningRequest, safeProvisioningResponse } from '../_shared/provisioning.ts'

Deno.serve(async req => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: corsHeaders(req) })
  if (req.method !== 'POST') return jsonResponse({ error: 'method_not_allowed' }, 405, req)

  let requestId: string | null = null
  try {
    const { service: privileged, context, userId } = await authenticateCaller(req)
    const service = privileged
    const profile = profileFrom(context)
    if (profile?.user_type !== 'super_admin' || profile.school_id !== null || !hasPermission(context, 'platform.schools.manage')) {
      return jsonResponse({ error: 'forbidden' }, 403, req)
    }
    const input = await req.json() as Record<string, unknown>
    const text = (key: string, max: number) => typeof input[key] === 'string' && (input[key] as string).trim().length <= max ? (input[key] as string).trim() : ''
    const name = text('name', 255)
    const code = text('code', 50)
    const adminName = text('admin_name', 255)
    const adminEmail = text('admin_email', 100).toLowerCase()
    const timezone = text('timezone', 64)
    const currency = text('currency', 3).toUpperCase()
    const status = text('status', 20)
    const email = text('email', 255).toLowerCase()
    if (!name || !/^[A-Za-z0-9_-]+$/.test(code) || !adminName
      || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adminEmail) || !timezone
      || (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))
      || !/^[A-Z]{3}$/.test(currency) || !['active', 'inactive', 'suspended', 'trial'].includes(status)) {
      return jsonResponse({ error: 'invalid_input' }, 400, req)
    }
    requestId = req.headers.get('idempotency-key')
    if (!validRequestId(requestId)) return jsonResponse({ error: 'idempotency_key_required' }, 400, req)

    const payload = {
      name, code, admin_name: adminName, admin_email: adminEmail,
      registration_number: text('registration_number', 255) || null,
      email: email || null, phone: text('phone', 255) || null, address: text('address', 255) || null,
      timezone, currency, status,
    }
    const request = await reserveProvisioningRequest(service, requestId, 'school_create', userId, payload)
    if (request.state === 'failed_terminal' || request.state === 'cancelling') {
      return jsonResponse({ error: 'request_not_resumable', request_id: requestId, state: request.state }, 409, req)
    }
    const result = await runProvisioningRequest(service, request)
    return jsonResponse(safeProvisioningResponse(requestId, result), 200, req)
  } catch (error) {
    console.error('School provisioning request failed', error instanceof ProvisioningError ? error.code : 'unknown')
    const message = error instanceof Error ? error.message : ''
    return jsonResponse({ error: message === 'unauthorized' ? 'unauthorized' : message === 'unprovisioned' ? 'unprovisioned' : 'provisioning_incomplete', request_id: requestId, recovery_required: !!requestId }, message === 'unauthorized' ? 401 : message === 'unprovisioned' ? 403 : 503, req)
  }
})
