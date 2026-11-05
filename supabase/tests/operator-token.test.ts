import { expiringTokenMatches } from '../functions/_shared/operator-token.ts'

function assert(value: unknown, message: string): asserts value {
  if (!value) throw new Error(message)
}

Deno.test('operator tokens require sufficient entropy and an unexpired configured deadline', () => {
  const now = Date.parse('2026-11-06T12:00:00Z')
  const token = '0123456789abcdef0123456789abcdef'
  assert(expiringTokenMatches(token, token, '2026-11-06T12:05:00Z', now), 'valid token was rejected')
  assert(!expiringTokenMatches(token, token, '2026-11-06T11:59:59Z', now), 'expired token was accepted')
  assert(!expiringTokenMatches(token, token, undefined, now), 'missing expiry was accepted')
  assert(!expiringTokenMatches('short', 'short', '2026-11-06T12:05:00Z', now), 'short token was accepted')
})

Deno.test('rotating an operator token invalidates the old value', () => {
  const now = Date.parse('2026-11-06T12:00:00Z')
  const oldToken = 'old0123456789abcdef0123456789abcdef'
  const newToken = 'new0123456789abcdef0123456789abcdef'
  const expiry = '2026-11-06T12:05:00Z'
  assert(!expiringTokenMatches(newToken, oldToken, expiry, now), 'old token remained valid after rotation')
  assert(expiringTokenMatches(newToken, newToken, expiry, now), 'new rotated token was rejected')
})
