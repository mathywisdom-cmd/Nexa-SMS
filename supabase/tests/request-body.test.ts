import { readJsonObject, RequestBodyError } from '../functions/_shared/http.ts'

function assert(value: unknown, message: string): asserts value {
  if (!value) throw new Error(message)
}

async function rejectsWith(input: RequestInfo | URL, init: RequestInit, code: string) {
  try {
    await readJsonObject(new Request(input, init), 32)
  } catch (error) {
    assert(error instanceof RequestBodyError && error.code === code, `expected ${code}, got ${String(error)}`)
    return
  }
  throw new Error(`expected ${code} rejection`)
}

Deno.test('bounded request JSON accepts a small object', async () => {
  const request = new Request('https://nexa.invalid', {
    method: 'POST', headers: { 'content-type': 'application/json' }, body: '{"ok":true}',
  })
  const body = await readJsonObject(request, 32)
  assert(body.ok === true, 'valid JSON body was not returned')
})

Deno.test('bounded request JSON rejects oversized declared content', async () => {
  await rejectsWith('https://nexa.invalid', {
    method: 'POST', headers: { 'content-type': 'application/json', 'content-length': '100' }, body: '{}',
  }, 'payload_too_large')
})

Deno.test('bounded request JSON rejects oversized streamed content without Content-Length', async () => {
  const stream = new ReadableStream<Uint8Array>({
    start(controller) { controller.enqueue(new TextEncoder().encode('x'.repeat(64))); controller.close() },
  })
  await rejectsWith('https://nexa.invalid', {
    method: 'POST', headers: { 'content-type': 'application/json' }, body: stream, duplex: 'half',
  } as RequestInit, 'payload_too_large')
})

Deno.test('bounded request JSON rejects malformed, empty, and missing bodies', async () => {
  await rejectsWith('https://nexa.invalid', {
    method: 'POST', headers: { 'content-type': 'application/json' }, body: '{',
  }, 'invalid_json')
  await rejectsWith('https://nexa.invalid', {
    method: 'POST', headers: { 'content-type': 'application/json' }, body: '',
  }, 'missing_body')
  await rejectsWith('https://nexa.invalid', {
    method: 'POST', headers: { 'content-type': 'application/json' },
  }, 'missing_body')
  await rejectsWith('https://nexa.invalid', {
    method: 'POST', headers: { 'content-type': 'text/plain' }, body: '{}',
  }, 'invalid_content_type')
})
