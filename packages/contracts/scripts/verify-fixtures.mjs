import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const schema = JSON.parse(readFileSync(new URL('monitor-foundation.schema.json', root), 'utf8'));
const cases = JSON.parse(readFileSync(new URL('fixtures/contract-cases.json', root), 'utf8'));
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function validate(value, rule) {
  if (rule.$ref) {
    const name = rule.$ref.replace('#/$defs/', '');
    return validate(value, schema.$defs[name]);
  }
  if ('const' in rule && value !== rule.const) return false;
  if (rule.enum && !rule.enum.includes(value)) return false;

  const acceptedTypes = Array.isArray(rule.type) ? rule.type : rule.type ? [rule.type] : [];
  if (acceptedTypes.length && !acceptedTypes.some(type => matchesType(value, type))) return false;
  if (value === null) return true;

  if (rule.type === 'object') {
    if ((rule.required ?? []).some(key => !(key in value))) return false;
    if (rule.additionalProperties === false) {
      if (Object.keys(value).some(key => !(key in (rule.properties ?? {})))) return false;
    }
    for (const [key, propertyRule] of Object.entries(rule.properties ?? {})) {
      if (key in value && !validate(value[key], propertyRule)) return false;
    }
  }

  if (rule.type === 'array') {
    if (rule.minItems !== undefined && value.length < rule.minItems) return false;
    if (rule.maxItems !== undefined && value.length > rule.maxItems) return false;
    if (rule.uniqueItems && new Set(value.map(item => JSON.stringify(item))).size !== value.length) return false;
    if (rule.items && value.some(item => !validate(item, rule.items))) return false;
  }

  if (typeof value === 'string') {
    if (rule.minLength !== undefined && value.length < rule.minLength) return false;
    if (rule.maxLength !== undefined && value.length > rule.maxLength) return false;
    if (rule.pattern && !new RegExp(rule.pattern).test(value)) return false;
    if (rule.format === 'uuid' && !uuid.test(value)) return false;
    if (rule.format === 'uri') {
      try { new URL(value); } catch { return false; }
    }
    if (rule.format === 'date-time' && (Number.isNaN(Date.parse(value)) || !/(?:Z|[+-]\d\d:\d\d)$/.test(value))) return false;
  }

  if (typeof value === 'number' && rule.minimum !== undefined && value < rule.minimum) return false;
  return true;
}

function matchesType(value, type) {
  return {
    null: value === null,
    object: value !== null && typeof value === 'object' && !Array.isArray(value),
    array: Array.isArray(value),
    string: typeof value === 'string',
    integer: Number.isInteger(value),
    boolean: typeof value === 'boolean',
  }[type] ?? false;
}

const definitions = {
  identity: 'identity',
  filter: 'filter',
  transition: 'transition',
  statusSummary: 'statusSummary',
  incident: 'incident',
  checkSync: 'checkSync',
};

assert.deepEqual(schema.$defs.monitorType.enum, ['server', 'website', 'api']);
assert.deepEqual(schema.$defs.lifecycleState.enum, ['healthy', 'warn', 'down', 'recovering']);
assert.deepEqual(schema.$defs.severity.enum, ['warn', 'critical']);
for (const fixture of cases) {
  assert.equal(validate(fixture.input, schema.$defs[definitions[fixture.kind]]), fixture.valid, fixture.name);
}
console.log(`Verified ${cases.length} shared JSON-schema/TypeScript contract fixtures.`);
