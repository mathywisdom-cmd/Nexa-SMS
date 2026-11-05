import { authenticateCaller, corsHeaders, hasPermission, jsonResponse, profileFrom, readJsonObject, requestBodyErrorResponse, reserveProvisioningRequest, validRequestId, ProvisioningError } from '../_shared/http.ts'
import { runProvisioningRequest, safeProvisioningResponse } from '../_shared/provisioning.ts'

const allowedSchoolTypes = new Set(['teacher', 'accountant', 'librarian', 'student', 'parent'])

Deno.serve(async req => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: corsHeaders(req) })
  if (req.method !== 'POST') return jsonResponse({ error: 'method_not_allowed' }, 405, req)

  let requestId: string | null = null
  try {
    const { service: privileged, context, userId } = await authenticateCaller(req)
    const service = privileged
    const actor = profileFrom(context)
    if (actor?.user_type !== 'admin' || !Number.isInteger(actor.school_id) || !hasPermission(context, 'school.manage')) {
      return jsonResponse({ error: 'forbidden' }, 403, req)
    }

    const input = await readJsonObject(req, 12 * 1024)
    const stringValue = (key: string, max: number) => typeof input[key] === 'string' && (input[key] as string).trim().length <= max ? (input[key] as string).trim() : ''
    const name = stringValue('name', 150)
    const email = stringValue('email', 100).toLowerCase()
    const userType = stringValue('user_type', 30)
    const gender = stringValue('gender', 50)
    const address = stringValue('address', 120)
    const phone = stringValue('phone', 20)
    const empDate = stringValue('emp_date', 30)
    const stateId = Number(input.state_id)
    const lgaId = Number(input.lga_id)
    const nationalityId = Number(input.nal_id)
    if (name.length < 6 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !allowedSchoolTypes.has(userType)
      || !gender || address.length < 6 || (phone && phone.length < 6)
      || !Number.isInteger(stateId) || !Number.isInteger(lgaId) || !Number.isInteger(nationalityId)) {
      return jsonResponse({ error: 'invalid_input' }, 400, req)
    }
    requestId = req.headers.get('idempotency-key')
    if (!validRequestId(requestId)) return jsonResponse({ error: 'idempotency_key_required' }, 400, req)

    // Deterministic per request so retry payloads hash identically.
    const username = `NEXA/STAFF/REQUEST/${requestId.replaceAll('-', '').toUpperCase()}`
    const payload = {
      name, email, user_type: userType, username, gender, phone: phone || null, address,
      state_id: stateId, lga_id: lgaId, nal_id: nationalityId, emp_date: empDate || null,
    }
    const request = await reserveProvisioningRequest(service, requestId, 'school_user_create', userId, payload)
    if (request.state === 'failed_terminal' || request.state === 'cancelling') {
      return jsonResponse({ error: 'request_not_resumable', request_id: requestId, state: request.state }, 409, req)
    }
    const result = await runProvisioningRequest(service, request)
    return jsonResponse(safeProvisioningResponse(requestId, result), 200, req)
  } catch (error) {
    const bodyError = requestBodyErrorResponse(error, req)
    if (bodyError) return bodyError
    console.error('School-user provisioning request failed', error instanceof ProvisioningError ? error.code : 'unknown')
    const message = error instanceof Error ? error.message : ''
    return jsonResponse({ error: message === 'unauthorized' ? 'unauthorized' : message === 'unprovisioned' ? 'unprovisioned' : 'provisioning_incomplete', request_id: requestId, recovery_required: !!requestId }, message === 'unauthorized' ? 401 : message === 'unprovisioned' ? 403 : 503, req)
  }
})
