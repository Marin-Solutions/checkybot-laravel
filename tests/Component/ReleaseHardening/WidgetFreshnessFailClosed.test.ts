import {
  StatusWidgetTimelineProvider,
  renderStatusWidget,
  type WidgetFetch,
  type WidgetResponse,
  type WidgetViewModel,
} from '../../../mobile/src/widget/StatusWidgetTimeline';

const updatedAt = '2026-08-08T12:00:00.000Z';
const envelope = {
  data: {
    counts: {
      servers: { healthy: 2, warn: 0, down: 0 },
      websites: { healthy: 3, warn: 0, down: 0 },
      apis: { healthy: 1, warn: 0, down: 0 },
    },
    updated_at: updatedAt as string | null,
    stale: false,
  },
};

const response = (status: number, body: unknown = envelope): WidgetResponse => ({
  status,
  json: async () => body,
});

function provider(request: WidgetFetch, nowMs: number) {
  return new StatusWidgetTimelineProvider(
    'https://status.example.test',
    { getStatusApiToken: async () => 'test-status-token' },
    request,
    () => nowMs,
  );
}

function expectNoHealthySemantics(view: WidgetViewModel) {
  expect(view.tone).not.toBe('healthy-green');
  expect(view.statusLabel).not.toMatch(/all systems healthy/i);
  expect(view.phase).not.toBe('healthy');
  expect(JSON.stringify(view)).not.toContain('healthy-green');
}

test.each([
  [0, 'Updated 0m ago'],
  [900, 'Updated 15m ago'],
] as const)('age is visible and healthy remains fresh through exactly %i seconds', async (seconds, ageLabel) => {
  const nowMs = Date.parse(updatedAt) + seconds * 1000;
  const entry = await provider(jest.fn(async () => response(200)), nowMs).scheduledReload();
  const view = renderStatusWidget(entry, undefined, nowMs);

  expect(view.phase).toBe('healthy');
  expect(view.tone).toBe('healthy-green');
  expect(view.statusLabel).toBe('All systems healthy');
  expect(view.updatedLabel).toBe(ageLabel);
});

test('local age greater than 900 seconds is dimmed stale and never healthy green', async () => {
  const nowMs = Date.parse(updatedAt) + 901_000;
  const entry = await provider(jest.fn(async () => response(200)), nowMs).scheduledReload();
  const view = renderStatusWidget(entry, undefined, nowMs);

  expect(view).toMatchObject({ phase: 'stale', tone: 'warning-dimmed', dimmed: true, statusLabel: 'Stale data' });
  expect(view.updatedLabel).toBe('Updated 15m ago');
  expectNoHealthySemantics(view);
});

test.each([
  ['updated_at=null', { updated_at: null, stale: false }],
  ['server stale=true', { updated_at: updatedAt, stale: true }],
] as const)('%s receives the warning/dimmed stale treatment with visible age', async (_label, freshness) => {
  const body = structuredClone(envelope);
  Object.assign(body.data, freshness);
  const nowMs = Date.parse(updatedAt) + 10 * 60_000;
  const entry = await provider(jest.fn(async () => response(200, body)), nowMs).scheduledReload();
  const view = renderStatusWidget(entry, undefined, nowMs);

  expect(view).toMatchObject({ phase: 'stale', tone: 'warning-dimmed', dimmed: true, statusLabel: 'Stale data' });
  expect(view.updatedLabel).toMatch(/^Updated \d+m ago$/);
  expectNoHealthySemantics(view);
});

test.each([
  ['401', jest.fn(async () => response(401)), 'auth', 'Authentication required'],
  ['403', jest.fn(async () => response(403)), 'auth', 'Authentication required'],
  ['transport failure', jest.fn(async () => { throw new Error('offline'); }), 'offline', 'Status unavailable'],
] as const)('%s renders a distinct non-green error/auth state with age always visible', async (_label, request, phase, label) => {
  const nowMs = Date.parse(updatedAt) + 10 * 60_000;
  const timeline = provider(request, nowMs);
  // Prime the production provider so the failure path also proves cached age/count retention.
  const primed = provider(jest.fn(async () => response(200)), nowMs);
  const successful = await primed.scheduledReload();
  expect(renderStatusWidget(successful, undefined, nowMs).updatedLabel).toBe('Updated 10m ago');

  const entry = await timeline.scheduledReload();
  const view = renderStatusWidget(entry, undefined, nowMs);
  expect(view).toMatchObject({ phase, tone: 'neutral', dimmed: false, statusLabel: label });
  expect(view.updatedLabel).toMatch(/^Updated \d+m ago$/);
  expectNoHealthySemantics(view);
});

test('transport failure after a success keeps cached summary and source age without healthy semantics', async () => {
  let nowMs = Date.parse(updatedAt) + 10 * 60_000;
  const request = jest.fn<ReturnType<WidgetFetch>, Parameters<WidgetFetch>>()
    .mockResolvedValueOnce(response(200))
    .mockRejectedValueOnce(new Error('offline'));
  const timeline = provider(request, nowMs);
  await timeline.scheduledReload();
  nowMs += 60_000;

  const failed = await timeline.scheduledReload();
  const view = renderStatusWidget(failed, undefined, nowMs);
  expect(failed.summary?.counts.servers.healthy).toBe(2);
  expect(view.updatedLabel).toBe('Updated 11m ago');
  expect(view.statusLabel).toBe('Status unavailable');
  expectNoHealthySemantics(view);
});
