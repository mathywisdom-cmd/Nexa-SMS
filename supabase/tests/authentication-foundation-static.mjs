import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const sql = readFileSync(path.join(root, 'supabase/migrations/20261105161648_authentication_foundation.sql'), 'utf8')
// These checks validate SQL semantics, not the migration author's line wrapping.
const sqlForAssertions = sql.replace(/\s+/g, ' ')
const databaseTest = readFileSync(path.join(root, 'supabase/tests/authentication_foundation.sql'), 'utf8')

function normalizeTypes(value) {
  const aliases = new Map([
    ['smallint', 'int2'],
    ['integer', 'int4'],
    ['int', 'int4'],
    ['bigint', 'int8'],
    ['decimal', 'numeric'],
    ['real', 'float4'],
    ['double precision', 'float8'],
    ['boolean', 'bool'],
    ['character varying', 'varchar'],
    ['timestamp with time zone', 'timestamptz'],
    ['timestamp without time zone', 'timestamp'],
    ['time with time zone', 'timetz'],
    ['time without time zone', 'time'],
  ])

  return value.split(',').map(part => {
    const type = part.trim().toLowerCase().replace(/\s+/g, ' ')
    return aliases.get(type) ?? type
  }).join(',')
}

const declarations = new Map()
const parameterNames = new Map()
const parameterTypes = new Map()
for (const match of sql.matchAll(/create\s+or\s+replace\s+function\s+([\w.]+)\s*\(([\s\S]*?)\)\s*returns/gi)) {
  const args = match[2].trim()
  const parts = args ? args.split(',').map(arg => arg.trim().split(/\s+/)) : []
  const types = parts.map(arg => {
    const defaultIndex = arg.findIndex(token => token.toLowerCase() === 'default')
    const declaration = arg.slice(0, defaultIndex < 0 ? arg.length : defaultIndex)
    const modeOffset = ['in', 'out', 'inout', 'variadic'].includes(declaration[0].toLowerCase()) ? 1 : 0
    return declaration.slice(modeOffset + 1).join(' ')
  })
  const name = match[1].toLowerCase()
  declarations.set(name, normalizeTypes(types.join(',')))
  parameterNames.set(name, parts.map(arg => arg[0]))
  parameterTypes.set(name, types.map(type => normalizeTypes(type)))
}

let privilegeStatements = 0
const ownershipStatements = new Set()
const revokeStatements = new Set()
const grantStatements = new Set()
for (const match of sql.matchAll(/(?:alter\s+function|revoke\s+all\s+on\s+function|grant\s+execute\s+on\s+function)\s+([\w.]+)\s*\(([^)]*)\)/gi)) {
  const name = match[1].toLowerCase()
  assert.ok(declarations.has(name), `privilege/ownership statement references undeclared function ${name}`)
  assert.equal(normalizeTypes(match[2]), declarations.get(name), `argument signature mismatch for ${name}`)
  privilegeStatements++
  const statement = match[0].toLowerCase()
  if (statement.startsWith('alter function')) ownershipStatements.add(name)
  if (statement.startsWith('revoke all')) revokeStatements.add(name)
  if (statement.startsWith('grant execute')) grantStatements.add(name)
}
for (const name of declarations.keys()) {
  assert.ok(ownershipStatements.has(name), `missing explicit owner statement for ${name}`)
  assert.ok(revokeStatements.has(name), `missing explicit revoke statement for ${name}`)
  if (['private.arm_owner_bootstrap_credential', 'private.bind_provisioning_auth_identity'].includes(name)) {
    assert.ok(!grantStatements.has(name), `${name} must remain owner/trigger-only`)
  } else {
    assert.ok(grantStatements.has(name), `missing explicit execution grant for ${name}`)
  }
}

for (const match of databaseTest.matchAll(/has_function_privilege\(\s*'[^']+'\s*,\s*'([\w.]+)\(([^)]*)\)'/gi)) {
  const name = match[1].toLowerCase()
  if (!declarations.has(name)) continue
  assert.equal(normalizeTypes(match[2]), declarations.get(name), `integration-test signature mismatch for ${name}`)
}

assert.match(sqlForAssertions, /create\s+unique\s+index\s+users_single_platform_owner[\s\S]*?where\s+user_type\s*=\s*'super_admin'/i)
assert.match(sqlForAssertions, /pg_advisory_xact_lock\(7128649234::bigint\)/i)
assert.match(sqlForAssertions, /p_actor_auth_user_id\s+uuid/i)
assert.match(sqlForAssertions, /profile_has_permission\(actor_profile_id,\s*'platform\.schools\.manage'\)\s+is\s+not\s+true/i)
assert.match(sqlForAssertions, /profile_has_permission\(actor_profile_id,\s*'school\.manage'\)\s+is\s+not\s+true/i)
assert.equal((sql.match(/set_config\('request\.jwt\.claim\.sub',\s*p_actor_auth_user_id::text,\s*true\)/gi) ?? []).length, 2,
  'school provisioning audit writes must bind only the SQL-verified actor as a transaction-local JWT subject')
assert.match(sqlForAssertions, /create\s+table\s+private\.provisioning_requests/i)
assert.match(sqlForAssertions, /grant\s+select,\s*insert\s+on\s+public\.users,\s*public\.schools\s+to\s+service_role/i)
assert.match(sqlForAssertions, /grant\s+select\s+on\s+auth\.users\s+to\s+service_role/i)
assert.match(sqlForAssertions, /grant\s+insert\s+on\s+public\.staff_records\s+to\s+service_role/i)
assert.match(sqlForAssertions, /grant\s+select,\s*insert\s+on\s+public\.audit_logs\s+to\s+service_role/i)
assert.match(sqlForAssertions, /request_row\.payload_hash\s*<>\s*p_payload_hash[\s\S]*?request_row\.state\s+not\s+in\s*\('completed',\s*'failed_terminal'\)\s+and\s+request_row\.payload\s*<>\s*p_payload/i,
  'completed or terminal idempotency retries must compare the retained payload hash after clearing payload data')
assert.match(sqlForAssertions, /state\s*=\s*case\s+when\s+auth_user_id\s+is\s+null\s+then\s+'pending'\s+else\s+'identity_created'\s+end/i,
  'recoverable requests with a linked Auth identity must resume at identity_created')
assert.match(sqlForAssertions, /provisioning_payload_has_credentials\(payload\)/i)
assert.match(sqlForAssertions, /jsonb_array_elements\(p_value\)/i)
assert.match(sqlForAssertions, /state\s+in\s*\('pending',\s*'identity_created',\s*'profile_created',\s*'completed',\s*'failed_recoverable',\s*'failed_terminal',\s*'cancelling'\)/i)
assert.match(sqlForAssertions, /request_row\.payload\s*<>\s*p_payload/i)
assert.match(sqlForAssertions, /begin_provisioning_cancellation/i)
assert.match(sqlForAssertions, /finish_provisioning_cancellation/i)
assert.match(sqlForAssertions, /request_row\.state\s+not\s+in\s*\('failed_recoverable',\s*'failed_terminal',\s*'cancelling'\)/i)
assert.match(sqlForAssertions, /recovery_channel\s*=\s*case\s+when\s+p_operator_auth_user_id\s+is\s+null\s+then\s+'bootstrap_operator'/i)
assert.match(sqlForAssertions, /resume_owner_bootstrap_provisioning\(uuid\)/i)
assert.match(sqlForAssertions, /grant\s+select,\s*insert,\s*update\s+on\s+private\.provisioning_requests\s+to\s+service_role/i)
assert.match(sqlForAssertions, /raw_app_meta_data->>'nexasms_provisioning_request_id'\s*=\s*p_request_id::text/i)
assert.doesNotMatch(sqlForAssertions, /raw_user_meta_data->>'nexasms_provisioning_request_id'/i)
assert.match(sqlForAssertions, /create\s+table\s+private\.provisioning_identity_map/i)
assert.match(sqlForAssertions, /provisioning_identity_map_active_email_uq/i)
assert.match(sqlForAssertions, /create\s+or\s+replace\s+function\s+public\.begin_provisioning_identity_creation\s*\(\s*p_request_id\s+uuid\s*,\s*p_lease_token\s+uuid\s*\)/i)
assert.match(sqlForAssertions, /create\s+trigger\s+auth_users_bind_provisioning_identity[\s\S]*?before\s+insert\s+on\s+auth\.users/i)
assert.match(sqlForAssertions, /new\.raw_app_meta_data\s*:=/i)
assert.match(sqlForAssertions, /if\s+new\.invited_at\s+is\s+null\s+then\s+return\s+new/i,
  'ordinary signups or unrelated Auth Admin creates must not consume an invite intent')
assert.match(sqlForAssertions, /create\s+or\s+replace\s+function\s+public\.reserve_owner_bootstrap_request\s*\(\s*p_request_id\s+uuid\s*,\s*p_payload\s+jsonb\s*,\s*p_payload_hash\s+text\s*,\s*p_token_hash\s+text\s*,\s*p_source_hash\s+text\s*\)/i)
assert.deepEqual(parameterNames.get('public.reserve_owner_bootstrap_request'), [
  'p_request_id', 'p_payload', 'p_payload_hash', 'p_token_hash', 'p_source_hash',
], 'owner bootstrap parameter names and order must match the migration contract')
assert.deepEqual(parameterTypes.get('public.reserve_owner_bootstrap_request'), [
  'uuid', 'jsonb', 'text', 'text', 'text',
], 'owner bootstrap parameter types and order must match the migration contract')
assert.match(sqlForAssertions, /p_payload->>'email'\s+is\s+null\s+or\s+p_payload->>'name'\s+is\s+null/i,
  'database bootstrap input validation must explicitly reject missing required values')
assert.match(sqlForAssertions, /\(?\s*p_payload\s*-\s*'email'\s*-\s*'name'\s*\)?\s*<>\s*'\{\}'::jsonb/i,
  'bootstrap payload must reject every key other than email and name')
assert.doesNotMatch(sqlForAssertions, /jsonb_object_length\s*\(/i,
  'bootstrap payload validation must use PostgreSQL-supported JSONB operations')
assert.match(sqlForAssertions, /consumed_request_id\s+<>\s+p_request_id[\s\S]*?bootstrap_replayed/i)
assert.match(sqlForAssertions, /owner bootstrap credential was not atomically consumed for this request/i)
assert.match(sqlForAssertions, /private\.arm_owner_bootstrap_credential\(text, timestamptz\) owner to postgres/i)
assert.match(sqlForAssertions, /create\s+or\s+replace\s+function\s+public\.consume_owner_bootstrap_rate_limit\(p_source_hash text\)/i)
assert.match(sqlForAssertions, /create\s+table\s+private\.owner_bootstrap_security_events/i)
assert.match(sqlForAssertions, /lookup_provisioning_identity/i)
assert.match(sqlForAssertions, /resume_owner_bootstrap_provisioning/i)
assert.match(sqlForAssertions, /create\s+or\s+replace\s+function\s+public\.claim_provisioning_request\s*\(\s*p_request_id\s+uuid\s*,\s*p_lease_token\s+uuid\s*\)/i)
assert.match(sqlForAssertions, /create\s+or\s+replace\s+function\s+public\.renew_provisioning_lease\s*\(\s*p_request_id\s+uuid\s*,\s*p_lease_token\s+uuid\s*\)/i)
assert.match(sqlForAssertions, /lease_expires_at\s*=\s*clock_timestamp\(\)\s*\+\s*interval\s+'10 minutes'/i)
assert.match(sqlForAssertions, /request_row\.lease_token\s+is\s+distinct\s+from\s+p_lease_token/i)
assert.match(sqlForAssertions, /request_row\.lease_expires_at\s*<=\s*clock_timestamp\(\)/i)
assert.match(sqlForAssertions, /alter\s+function\s+public\.renew_provisioning_lease\s*\(\s*uuid\s*,\s*uuid\s*\)\s+owner\s+to\s+postgres/i)
assert.match(sqlForAssertions, /revoke\s+all\s+on\s+function\s+public\.renew_provisioning_lease\s*\(\s*uuid\s*,\s*uuid\s*\)\s+from\s+public,\s*anon,\s*authenticated/i)
assert.match(sqlForAssertions, /grant\s+execute\s+on\s+function\s+public\.renew_provisioning_lease\s*\(\s*uuid\s*,\s*uuid\s*\)\s+to\s+service_role/i)
assert.match(sqlForAssertions, /create\s+or\s+replace\s+function\s+public\.transition_provisioning_request\s*\([\s\S]*?p_lease_token\s+uuid\s+default\s+null/i)
assert.match(sqlForAssertions, /request_row\.state\s+not\s+in\s*\('identity_created',\s*'profile_created'\)/i)
assert.match(sqlForAssertions, /create\s+or\s+replace\s+function\s+public\.record_provisioning_identity\s*\(\s*p_request_id\s+uuid\s*,\s*p_auth_user_id\s+uuid\s*,\s*p_lease_token\s+uuid\s*\)/i)
assert.match(sqlForAssertions, /constraint\s+provisioning_requests_lease_pair/i)
assert.match(sqlForAssertions, /request_row\.state\s+not\s+in\s*\('pending',\s*'identity_created',\s*'profile_created'\)/i)
assert.deepEqual(parameterNames.get('public.provision_school_and_first_admin'), [
  'p_request_id', 'p_auth_user_id', 'p_actor_auth_user_id', 'p_school_name', 'p_school_code', 'p_registration_number',
  'p_school_email', 'p_phone', 'p_address', 'p_timezone', 'p_currency', 'p_status', 'p_admin_name', 'p_lease_token',
])
assert.deepEqual(parameterNames.get('public.provision_school_staff_profile'), [
  'p_request_id', 'p_auth_user_id', 'p_actor_auth_user_id', 'p_name', 'p_user_type', 'p_username', 'p_gender',
  'p_phone', 'p_address', 'p_state_id', 'p_lga_id', 'p_nal_id', 'p_emp_date', 'p_lease_token',
])
assert.deepEqual(parameterNames.get('public.provision_platform_owner_profile'), [
  'p_request_id', 'p_auth_user_id', 'p_name', 'p_lease_token',
])

const login = readFileSync(path.join(root, 'supabase/functions/login-username/index.ts'), 'utf8')
assert.match(login, /import\s*\{[^}]*\bgetClients\b[^}]*\}/)
assert.match(login, /\.ilike\('username',\s*usernamePattern\)/)
assert.match(login, /temporarily_unavailable/)

for (const functionPath of [
  'supabase/functions/_shared/provisioning.ts',
  'supabase/functions/provisioning-recovery/index.ts',
]) {
  const source = readFileSync(path.join(root, functionPath), 'utf8')
  assert.match(source, /request_id/)
  assert.match(source, /profile_created|cancelling|failed_recoverable/)
}

const recovery = readFileSync(path.join(root, 'supabase/functions/provisioning-recovery/index.ts'), 'utf8')
assert.match(recovery, /authenticateCaller\(req\)/)
assert.match(recovery, /platform\.schools\.manage/)
assert.match(recovery, /NEXASMS_PROVISIONING_OPERATOR_TOKEN/)
assert.match(recovery, /request\.operation !== 'owner_bootstrap'/)
assert.match(recovery, /resume_owner_bootstrap_provisioning/)
assert.doesNotMatch(recovery, /Deno\.env\.get\(['"]SUPABASE_SERVICE_ROLE_KEY['"]\)/)
assert.match(login, /readJsonObject\(req,\s*4\s*\*\s*1024\)/)
assert.match(readFileSync(path.join(root, 'supabase/functions/bootstrap-owner/index.ts'), 'utf8'), /readJsonObject\(req,\s*8\s*\*\s*1024\)/)
assert.match(readFileSync(path.join(root, 'supabase/functions/provision-school/index.ts'), 'utf8'), /readJsonObject\(req,\s*16\s*\*\s*1024\)/)
assert.match(readFileSync(path.join(root, 'supabase/functions/provision-school-user/index.ts'), 'utf8'), /readJsonObject\(req,\s*12\s*\*\s*1024\)/)
assert.match(recovery, /readJsonObject\(req,\s*4\s*\*\s*1024\)/)
const bodyReader = readFileSync(path.join(root, 'supabase/functions/_shared/http.ts'), 'utf8')
assert.match(bodyReader, /Content-Length as the only limit/)
assert.match(bodyReader, /size > maxBytes/)
assert.match(bodyReader, /reader\.cancel\(\)/)
const bodyTests = readFileSync(path.join(root, 'supabase/tests/request-body.test.ts'), 'utf8')
for (const scenario of ['oversized declared content', 'oversized streamed content', 'malformed, empty, and missing bodies']) {
  assert.ok(bodyTests.includes(scenario), `missing bounded-body test scenario: ${scenario}`)
}
const cancellation = readFileSync(path.join(root, 'supabase/functions/_shared/recovery.ts'), 'utf8')
assert.match(cancellation, /begin_provisioning_cancellation/)
assert.match(cancellation, /finish_provisioning_cancellation/)
assert.match(cancellation, /getUserById\(authUserId\)/)
assert.match(cancellation, /identity_email/)
assert.match(readFileSync(path.join(root, 'supabase/config.toml'), 'utf8'), /\[functions\.provisioning-recovery\][\s\S]*?verify_jwt\s*=\s*false/i)
for (const functionPath of ['bootstrap-owner/index.ts', 'provision-school/index.ts', 'provision-school-user/index.ts']) {
  const source = readFileSync(path.join(root, 'supabase/functions', functionPath), 'utf8')
  assert.doesNotMatch(source, /changeProvisioningState\(/, `${functionPath} must not transition ledger state without the worker lease`)
}
const recoveryTests = readFileSync(path.join(root, 'supabase/tests/provisioning-recovery.test.ts'), 'utf8')
for (const scenario of [
  'invite failure remains retryable',
  'invite created identity but lost response reconciles after user metadata mutation',
  'profile insertion failure resumes',
  'committed SQL transaction with lost response',
  'same idempotency key is reused',
  'concurrent identical requests',
  'different owner bootstrap requests',
  'pre-existing unmarked Auth identity',
  'failed Auth compensation',
  'cancellation retry reconciles Auth deletion after response loss',
  'orphan Auth identity lookup',
  'missing server-controlled Auth marker is rejected',
  'lease renewal extends the live lease',
  'expired lease cannot be renewed',
  'competing worker cannot renew or claim',
  'wrong worker identity cannot renew',
  'lease renewal rejects cancellation and terminal states',
  'renewal failure stops provisioning',
]) assert.ok(recoveryTests.includes(scenario), `missing provisioning recovery test: ${scenario}`)
const operatorTokenTests = readFileSync(path.join(root, 'supabase/tests/operator-token.test.ts'), 'utf8')
assert.match(operatorTokenTests, /unexpired configured deadline/)
assert.match(operatorTokenTests, /rotating an operator token invalidates the old value/)
const integrationTests = readFileSync(path.join(root, 'supabase/tests/authentication-provisioning.integration.ts'), 'utf8')
assert.match(integrationTests, /resumedRetry\.state\s*===\s*'identity_created'[\s\S]*?resumedRetry\.auth_user_id\s*===\s*retryAuthId/,
  'PostgreSQL/Auth integration suite must verify that a linked identity retry resumes at identity_created')

const laravelProvider = readFileSync(path.join(root, 'app/Auth/LegacyPasswordUserProvider.php'), 'utf8')
assert.equal((laravelProvider.match(/whereNotNull\('password'\)/g) ?? []).length, 3)

console.log(`Static auth foundation checks passed (${declarations.size} SQL functions, ${privilegeStatements} signature checks).`)
