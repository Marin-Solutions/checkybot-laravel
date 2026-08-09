// Generated from packages/contracts/monitor-foundation.schema.json. Do not edit.
export const MONITOR_FOUNDATION_VERSION = 'monitor-foundation.v1' as const;
export const MONITOR_TYPES = ['server', 'website', 'api'] as const;
export const LIFECYCLE_STATES = ['healthy', 'warn', 'down', 'recovering'] as const;
export const SEVERITIES = ['warn', 'critical'] as const;
export const STATUS_KINDS = ['servers', 'websites', 'apis'] as const;
export const STATUS_STATES = ['healthy', 'warn', 'down'] as const;

export type MonitorType = (typeof MONITOR_TYPES)[number];
export type LifecycleState = (typeof LIFECYCLE_STATES)[number];
export type Severity = (typeof SEVERITIES)[number];
export type StatusKind = (typeof STATUS_KINDS)[number];
export type StatusState = (typeof STATUS_STATES)[number];
export type Freshness = 'fresh' | 'stale';
export interface MonitorFilter { types: MonitorType[]; states: LifecycleState[]; severities: Severity[] }
export interface StatusCounts { healthy: number; warn: number; down: number }
export interface StatusSummary { counts: Record<StatusKind, StatusCounts>; updated_at: string | null; stale: boolean }
export interface StatusSummaryResponse { data: StatusSummary }

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);
const exactKeys = (value: Record<string, unknown>, keys: readonly string[]): boolean => {
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  return actual.length === expected.length && actual.every((key, index) => key === expected[index]);
};
const parseEnum = <T extends string>(name: string, value: unknown, allowed: readonly T[]): T => {
  if (typeof value !== 'string' || !allowed.includes(value as T)) throw new Error(`Invalid ${name}.`);
  return value as T;
};
const parseUniqueEnumArray = <T extends string>(name: string, value: unknown, allowed: readonly T[]): T[] => {
  if (!Array.isArray(value) || value.length === 0) throw new Error(`Invalid ${name}.`);
  const parsed = value.map((item) => parseEnum(name, item, allowed));
  if (new Set(parsed).size !== parsed.length) throw new Error(`Invalid ${name}.`);
  return parsed;
};

export const parseLifecycleState = (value: unknown): LifecycleState =>
  parseEnum('lifecycle state', value, LIFECYCLE_STATES);
export const parseSeverity = (value: unknown): Severity => parseEnum('severity', value, SEVERITIES);

export function parseMonitorFilter(value: unknown): MonitorFilter {
  if (!isRecord(value) || !exactKeys(value, ['types', 'states', 'severities'])) throw new Error('Invalid monitor filter.');
  return {
    types: parseUniqueEnumArray('monitor types', value.types, MONITOR_TYPES),
    states: parseUniqueEnumArray('lifecycle states', value.states, LIFECYCLE_STATES),
    severities: parseUniqueEnumArray('severities', value.severities, SEVERITIES),
  };
}

function parseCounts(value: unknown): StatusCounts {
  if (!isRecord(value) || !exactKeys(value, STATUS_STATES)) throw new Error('Malformed status-summary counts.');
  const parsed = {} as StatusCounts;
  for (const state of STATUS_STATES) {
    const count = value[state];
    if (!Number.isInteger(count) || (count as number) < 0) throw new Error('Malformed status-summary counts.');
    parsed[state] = count as number;
  }
  return parsed;
}

export function parseStatusSummaryResponse(value: unknown): StatusSummaryResponse {
  if (!isRecord(value) || !exactKeys(value, ['data']) || !isRecord(value.data)) throw new Error('Malformed status-summary response.');
  const data = value.data;
  if (!exactKeys(data, ['counts', 'updated_at', 'stale']) || !isRecord(data.counts) || !exactKeys(data.counts, STATUS_KINDS)) {
    throw new Error('Malformed status-summary response.');
  }
  if (typeof data.stale !== 'boolean') throw new Error('Malformed status-summary freshness.');
  if (data.updated_at !== null && (typeof data.updated_at !== 'string' || !Number.isFinite(Date.parse(data.updated_at)))) {
    throw new Error('Malformed status-summary timestamp.');
  }
  const counts = {} as Record<StatusKind, StatusCounts>;
  for (const kind of STATUS_KINDS) counts[kind] = parseCounts(data.counts[kind]);
  return { data: { counts, updated_at: data.updated_at as string | null, stale: data.stale } };
}

export function deriveFreshness(summary: StatusSummary, nowMs = Date.now()): Freshness {
  if (summary.stale || summary.updated_at === null) return 'stale';
  const ageSeconds = Math.max(0, (nowMs - Date.parse(summary.updated_at)) / 1000);
  return ageSeconds > 900 ? 'stale' : 'fresh';
}
