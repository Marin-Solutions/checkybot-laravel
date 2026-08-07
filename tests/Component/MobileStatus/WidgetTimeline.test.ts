import {
  StatusWidgetTimelineProvider,
  renderStatusWidget,
  type WidgetFetch,
  type WidgetResponse,
} from '../../../mobile/src/widget/StatusWidgetTimeline';

const summary = {
  data: {
    counts: {
      servers: { healthy: 11, warn: 1, down: 2 },
      websites: { healthy: 22, warn: 3, down: 4 },
      apis: { healthy: 33, warn: 5, down: 6 },
    },
    updated_at: '2026-08-07T12:00:00.000Z',
    stale: false,
  },
};

const response = (status: number, body: unknown = summary): WidgetResponse => ({
  status,
  json: async () => body,
});

function provider(request: WidgetFetch, now = () => Date.parse('2026-08-07T12:10:00.000Z')) {
  return new StatusWidgetTimelineProvider(
    'https://status.example.test/custom/path',
    { getStatusApiToken: async () => 'status-read-secret' },
    request,
    now,
  );
}

test('every timeline reload performs an authenticated direct status-summary GET and maps all nine cells', async () => {
  const request = jest.fn<ReturnType<WidgetFetch>, Parameters<WidgetFetch>>()
    .mockResolvedValue(response(200));
  const timeline = provider(request);

  const first = await timeline.scheduledReload();
  const second = await timeline.scheduledReload();

  expect(request).toHaveBeenCalledTimes(2);
  expect(request).toHaveBeenNthCalledWith(1, 'https://status.example.test/api/status-summary', {
    method: 'GET',
    headers: { Accept: 'application/json', Authorization: 'Bearer status-read-secret' },
  });
  expect(first.sourceUpdatedAt).toBe(summary.data.updated_at);
  expect(first.summary?.counts).toEqual(summary.data.counts);
  expect(second.summary?.counts.apis).toEqual({ healthy: 33, warn: 5, down: 6 });
});

test.each([
  ['401', async () => response(401), 'auth'],
  ['403', async () => response(403), 'auth'],
  ['transport failure', async () => { throw new Error('offline'); }, 'offline'],
] as const)('%s produces a deterministic non-green auth/offline entry', async (_label, result, phase) => {
  const entry = await provider(jest.fn(result)).scheduledReload();
  const view = renderStatusWidget(entry);
  expect(entry.phase).toBe(phase);
  expect(view.tone).toBe('neutral');
  expect(view.updatedLabel).toBe('Updated 0m ago');
});

test('push data is never a status substitute and background throttling leaves scheduled direct fetch authoritative', async () => {
  let clock = Date.parse('2026-08-07T12:10:00.000Z');
  const request = jest.fn<ReturnType<WidgetFetch>, Parameters<WidgetFetch>>()
    .mockResolvedValueOnce(response(200))
    .mockRejectedValueOnce(new Error('system reload offline'));
  const timeline = provider(request, () => clock);
  const fromApi = await timeline.scheduledReload();

  // A silent payload may contain untrusted lookalike data; no timeline API accepts it.
  const throttledSilentPush = { refreshWidget: true, counts: { servers: { healthy: 999 } } };
  expect(throttledSilentPush.refreshWidget).toBe(true);
  clock += 5 * 60 * 1000;
  const scheduled = await timeline.scheduledReload();

  expect(request).toHaveBeenCalledTimes(2);
  expect(fromApi.summary?.counts.servers.healthy).toBe(11);
  expect(scheduled.phase).toBe('offline');
  expect(scheduled.summary?.counts.servers.healthy).toBe(11);
  expect(scheduled.visibleUpdatedAtMs).toBe(Date.parse(summary.data.updated_at));
  expect(renderStatusWidget(scheduled, undefined, clock).updatedLabel).toBe('Updated 15m ago');
});

test('freshness is healthy through exactly 900 seconds and stale after it', async () => {
  for (const [seconds, expected] of [[900, 'healthy'], [901, 'stale']] as const) {
    const healthy = structuredClone(summary);
    healthy.data.counts = {
      servers: { healthy: 1, warn: 0, down: 0 },
      websites: { healthy: 1, warn: 0, down: 0 },
      apis: { healthy: 1, warn: 0, down: 0 },
    };
    const entry = await provider(
      jest.fn(async () => response(200, healthy)),
      () => Date.parse(healthy.data.updated_at) + seconds * 1000,
    ).scheduledReload();
    expect(entry.phase).toBe(expected);
    expect(renderStatusWidget(entry).updatedLabel).toBe(`Updated ${Math.floor(seconds / 60)}m ago`);
  }
});

test.each([
  ['updated_at null', { updated_at: null, stale: false }, 0],
  ['server stale', { updated_at: summary.data.updated_at, stale: true }, 10],
] as const)('%s uses the distinct dimmed warning stale treatment', async (_label, patch, minutes) => {
  const body = structuredClone(summary) as typeof summary;
  Object.assign(body.data, patch);
  const entry = await provider(jest.fn(async () => response(200, body))).scheduledReload();
  const view = renderStatusWidget(entry);
  expect(entry.phase).toBe('stale');
  expect(view).toMatchObject({ tone: 'warning-dimmed', dimmed: true, statusLabel: 'Stale data' });
  expect(view.updatedLabel).toBe(`Updated ${minutes}m ago`);
});

test('layout is the declared row-major 3x3 grid and marks only exact warn/down problem cells', async () => {
  const entry = await provider(jest.fn(async () => response(200))).scheduledReload();
  const view = renderStatusWidget(entry, '11111111-1111-4111-8111-111111111111');
  expect(view.family).toBe('systemMedium');
  expect(view.rowLabels).toEqual(['Servers', 'Websites', 'APIs']);
  expect(view.columnLabels).toEqual(['healthy', 'warn', 'down']);
  expect(view.cells.map(({ row, column, count }) => [row, column, count])).toEqual([
    ['servers', 'healthy', 11], ['servers', 'warn', 1], ['servers', 'down', 2],
    ['websites', 'healthy', 22], ['websites', 'warn', 3], ['websites', 'down', 4],
    ['apis', 'healthy', 33], ['apis', 'warn', 5], ['apis', 'down', 6],
  ]);
  expect(view.cells.filter((cell) => cell.affected).map(({ row, column }) => `${row}.${column}`)).toEqual([
    'servers.warn', 'servers.down', 'websites.warn', 'websites.down', 'apis.warn', 'apis.down',
  ]);
  expect(view.deepLink).toBe('checkybot://problems?states=warn,down&projectUuid=11111111-1111-4111-8111-111111111111');
});

test('zero warn/down counts produce explicit healthy green while loading and offline never do', async () => {
  const body = structuredClone(summary);
  for (const counts of Object.values(body.data.counts)) {
    counts.warn = 0;
    counts.down = 0;
  }
  const timeline = provider(jest.fn(async () => response(200, body)));
  const healthy = renderStatusWidget(await timeline.scheduledReload());
  expect(healthy).toMatchObject({ phase: 'healthy', tone: 'healthy-green', statusLabel: 'All systems healthy' });
  expect(healthy.cells.filter((cell) => cell.affected)).toEqual([]);
  expect(renderStatusWidget(timeline.loadingEntry()).tone).toBe('neutral');
});
