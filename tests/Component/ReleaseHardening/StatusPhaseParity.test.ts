import type { StatusSummary } from '../../../mobile/src/contracts/monitor-domain.generated';
import { deriveStatusPhase as mobilePhase } from '../../../mobile/src/status/statusPhase';
import { deriveStatusPhase as webPhase } from '../../../resources/js/Components/CheckybotDashboard/statusPhase';

// The mobile bundle is built by Metro rooted at mobile/, so the status phase rule is implemented
// twice. This test is what keeps the two copies honest — if one is edited alone, it fails here.

const updatedAt = '2026-08-08T12:00:00Z';
const baseMs = Date.parse(updatedAt);

const countSets: Record<string, StatusSummary['counts']> = {
  empty: {
    servers: { healthy: 0, warn: 0, down: 0 },
    websites: { healthy: 0, warn: 0, down: 0 },
    apis: { healthy: 0, warn: 0, down: 0 },
  },
  healthy: {
    servers: { healthy: 4, warn: 0, down: 0 },
    websites: { healthy: 8, warn: 0, down: 0 },
    apis: { healthy: 3, warn: 0, down: 0 },
  },
  warning: {
    servers: { healthy: 4, warn: 1, down: 0 },
    websites: { healthy: 8, warn: 0, down: 0 },
    apis: { healthy: 3, warn: 0, down: 0 },
  },
  down: {
    servers: { healthy: 4, warn: 0, down: 2 },
    websites: { healthy: 8, warn: 0, down: 0 },
    apis: { healthy: 3, warn: 0, down: 0 },
  },
};

const freshnessSets: Record<string, Pick<StatusSummary, 'updated_at' | 'stale'>> = {
  fresh: { updated_at: updatedAt, stale: false },
  serverStale: { updated_at: updatedAt, stale: true },
  unknownTimestamp: { updated_at: null, stale: false },
};

const ageOffsetsMs = [0, 900_000, 900_001, 3_600_000];

const cases = Object.entries(countSets).flatMap(([countLabel, counts]) =>
  Object.entries(freshnessSets).flatMap(([freshnessLabel, freshness]) =>
    ageOffsetsMs.map((offset) => [
      `${countLabel} / ${freshnessLabel} / +${offset}ms`,
      { counts, ...freshness } as StatusSummary,
      baseMs + offset,
    ] as const)));

test.each(cases)('mobile and web agree on %s', (_label, summary, nowMs) => {
  expect(webPhase(summary, nowMs)).toBe(mobilePhase(summary, nowMs));
});

test.each(cases)('%s is only ever healthy when the data is fresh and problem-free', (_label, summary, nowMs) => {
  const phase = mobilePhase(summary, nowMs);
  if (phase !== 'healthy') return;

  expect(summary.stale).toBe(false);
  expect(summary.updated_at).not.toBeNull();
  expect(nowMs - Date.parse(summary.updated_at as string)).toBeLessThanOrEqual(900_000);
  const kinds = ['servers', 'websites', 'apis'] as const;
  expect(kinds.some((kind) => summary.counts[kind].warn > 0 || summary.counts[kind].down > 0)).toBe(false);
  expect(kinds.some((kind) => summary.counts[kind].healthy > 0)).toBe(true);
});
