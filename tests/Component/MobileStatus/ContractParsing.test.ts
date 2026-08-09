import {
  deriveFreshness,
  parseLifecycleState,
  parseMonitorFilter,
  parseSeverity,
  parseStatusSummaryResponse,
} from '../../../mobile/src/contracts/monitor-domain.generated';

const valid = {
  data: {
    counts: {
      servers: { healthy: 4, warn: 1, down: 0 },
      websites: { healthy: 7, warn: 0, down: 1 },
      apis: { healthy: 3, warn: 2, down: 0 },
    },
    updated_at: '2026-08-07T12:00:00Z',
    stale: false,
  },
};

test('parses status, severity, freshness and monitor filters from generated contracts', () => {
  const parsed = parseStatusSummaryResponse(valid).data;
  expect(parsed.counts.apis.warn).toBe(2);
  expect(parseLifecycleState('recovering')).toBe('recovering');
  expect(parseSeverity('critical')).toBe('critical');
  expect(parseMonitorFilter({ types: ['server'], states: ['warn', 'down'], severities: ['warn'] }))
    .toEqual({ types: ['server'], states: ['warn', 'down'], severities: ['warn'] });
  expect(deriveFreshness(parsed, Date.parse('2026-08-07T12:15:00Z'))).toBe('fresh');
  expect(deriveFreshness(parsed, Date.parse('2026-08-07T12:15:01Z'))).toBe('stale');
});

test('rejects unknown shared states and malformed nine-count responses', () => {
  expect(() => parseLifecycleState('paused')).toThrow('Invalid lifecycle state');
  expect(() => parseMonitorFilter({ types: ['server'], states: ['unknown'], severities: ['warn'] })).toThrow();
  const missingCell = structuredClone(valid);
  delete (missingCell.data.counts.apis as Partial<typeof missingCell.data.counts.apis>).down;
  expect(() => parseStatusSummaryResponse(missingCell)).toThrow('Malformed status-summary counts');
  const extraCell = structuredClone(valid);
  (extraCell.data.counts.servers as typeof extraCell.data.counts.servers & { unknown: number }).unknown = 9;
  expect(() => parseStatusSummaryResponse(extraCell)).toThrow('Malformed status-summary counts');
  const negative = structuredClone(valid);
  negative.data.counts.websites.warn = -1;
  expect(() => parseStatusSummaryResponse(negative)).toThrow('Malformed status-summary counts');
});
