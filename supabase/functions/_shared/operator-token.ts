export function tokenMatches(expected: string, supplied: string): boolean {
  const left = new TextEncoder().encode(expected)
  const right = new TextEncoder().encode(supplied)
  if (left.length < 32 || left.length !== right.length) return false
  let difference = 0
  for (let index = 0; index < left.length; index++) difference |= left[index] ^ right[index]
  return difference === 0
}

export function expiringTokenMatches(expected: string, supplied: string, expiresAt: string | undefined, now = Date.now()): boolean {
  const expiry = Date.parse(expiresAt ?? '')
  return Number.isFinite(expiry) && expiry > now && tokenMatches(expected, supplied)
}
