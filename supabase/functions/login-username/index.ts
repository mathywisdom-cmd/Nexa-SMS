import { corsHeaders, getClients, jsonResponse, readJsonObject, requestBodyErrorResponse } from '../_shared/http.ts'

const INVALID_LOGIN = { error: 'invalid_login', message: 'The supplied login details are invalid or the account is not available.' }

async function sha256(value: string): Promise<string> {
  const bytes = new TextEncoder().encode(value)
  const digest = await crypto.subtle.digest('SHA-256', bytes)
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('')
}

Deno.serve(async req => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: corsHeaders(req) })
  if (req.method !== 'POST') return jsonResponse({ error: 'method_not_allowed' }, 405, req)

  try {
    const { url, anon, service } = getClients()
    const body = await readJsonObject(req, 4 * 1024) as { identity?: unknown; password?: unknown }
    const identity = typeof body.identity === 'string' ? body.identity.trim() : ''
    const password = typeof body.password === 'string' ? body.password : ''
    if (!identity || identity.includes('@') || identity.length > 100 || !password || password.length > 1024) {
      return jsonResponse(INVALID_LOGIN, 401, req)
    }

    // Trust only the edge proxy's client IP header. Avoid putting every caller
    // into one shared "unknown" bucket if that header is unavailable.
    const address = req.headers.get('cf-connecting-ip')
    if (!address) return jsonResponse({ error: 'temporarily_unavailable' }, 503, req)
    const [identifierHash, ipHash] = await Promise.all([
      sha256(identity.toLocaleLowerCase('en-US')),
      sha256(address),
    ])
    const { data: allowed, error: limitError } = await service.rpc('consume_username_login_attempt', {
      p_identifier_hash: identifierHash,
      p_ip_hash: ipHash,
    })
    if (limitError) return jsonResponse({ error: 'temporarily_unavailable' }, 503, req)
    if (!allowed) return jsonResponse({ error: 'temporarily_unavailable' }, 429, req)

    // Escape ILIKE metacharacters so this remains an exact, case-insensitive
    // username comparison rather than a user-controlled pattern.
    const usernamePattern = identity.replace(/[\\%_]/g, '\\$&')
    const { data: profile } = await service.from('users')
      .select('email')
      .ilike('username', usernamePattern)
      .not('email', 'is', null)
      .not('auth_user_id', 'is', null)
      .maybeSingle()
    const email = profile?.email ?? 'nexasms-invalid-user@invalid.local'
    const authResponse = await fetch(`${url}/auth/v1/token?grant_type=password`, {
      method: 'POST',
      headers: { apikey: anon, 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    })
    if (!authResponse.ok) {
      await authResponse.body?.cancel()
      return jsonResponse(INVALID_LOGIN, 401, req)
    }
    const session = await authResponse.json() as Record<string, unknown>
    if (typeof session.access_token !== 'string' || typeof session.refresh_token !== 'string') {
      return jsonResponse(INVALID_LOGIN, 401, req)
    }
    return jsonResponse({
      access_token: session.access_token,
      refresh_token: session.refresh_token,
      expires_in: session.expires_in,
      expires_at: session.expires_at,
      token_type: session.token_type,
    }, 200, req)
  } catch (error) {
    const bodyError = requestBodyErrorResponse(error, req)
    if (bodyError) return bodyError
    console.error('Username sign-in failed', error instanceof Error ? error.name : 'unknown error')
    return jsonResponse({ error: 'temporarily_unavailable' }, 503, req)
  }
})
