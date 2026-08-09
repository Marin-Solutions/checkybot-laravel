import React from 'react';
import TestRenderer, { act, type ReactTestInstance } from 'react-test-renderer';
import Overview from '../../../resources/js/Pages/CheckybotDashboard/Overview';
import { overview, problem } from './fixtures';

// One minute after the fixture's updated_at, so the fixtures read as fresh unless a test says otherwise.
const now = () => Date.parse('2026-08-07T12:00:00Z');

function renderOverview(props = overview(), extra: Record<string, unknown> = {}) {
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(<Overview {...props} formatDate={(value) => `LOCAL ${value}`} now={now} {...extra} />);
  });
  return renderer;
}

function text(node: ReactTestInstance | TestRenderer.ReactTestRenderer): string {
  const json = 'toJSON' in node ? node.toJSON() : node;
  const collect = (value: unknown): string => {
    if (typeof value === 'string' || typeof value === 'number') return String(value);
    if (Array.isArray(value)) return value.map(collect).join(' ');
    if (value && typeof value === 'object' && 'children' in value) return collect((value as { children?: unknown }).children ?? []);
    return '';
  };
  return collect(json).replace(/\s+/g, ' ').trim();
}

it('renders exactly the canonical nine labeled values and an explicit fresh all-healthy state', () => {
  const renderer = renderOverview();
  const cells = renderer.root.findAll((node) => node.type === 'div' && typeof node.props['data-testid'] === 'string' && node.props['data-testid'].startsWith('status-'));
  expect(cells).toHaveLength(9);
  expect(cells.map((cell) => text(cell))).toEqual([
    'Servers · Healthy 3', 'Servers · Warning 0', 'Servers · Down 0',
    'Websites · Healthy 4', 'Websites · Warning 0', 'Websites · Down 0',
    'APIs · Healthy 2', 'APIs · Warning 0', 'APIs · Down 0',
  ]);
  expect(text(renderer)).toContain('Updated LOCAL 2026-08-07T11:59:00Z');
  expect(renderer.root.findByProps({ 'data-testid': 'all-healthy' })).toBeTruthy();
});

it('never presents stale data as healthy and renders problem and empty-project states', () => {
  const stale = renderOverview(overview({ summary: { ...overview().summary, stale: true } }));
  expect(text(stale.root.findByProps({ 'data-testid': 'stale-summary' }))).toContain('Status data is stale');
  expect(stale.root.findAllByProps({ 'data-testid': 'all-healthy' })).toHaveLength(0);

  const problems = renderOverview(overview({
    summary: {
      ...overview().summary,
      counts: { ...overview().summary.counts, websites: { healthy: 3, warn: 0, down: 1 } },
    },
    problems: [problem],
  }));
  expect(problems.root.findByProps({ 'data-testid': 'problem-summary' })).toBeTruthy();
  expect(problems.root.findByProps({ 'data-testid': 'problems-present' })).toBeTruthy();

  const emptyCounts = {
    servers: { healthy: 0, warn: 0, down: 0 },
    websites: { healthy: 0, warn: 0, down: 0 },
    apis: { healthy: 0, warn: 0, down: 0 },
  };
  const empty = renderOverview(overview({ summary: { counts: emptyCounts, stale: false, updated_at: null } }));
  expect(text(empty.root.findByProps({ 'data-testid': 'empty-project' }))).toContain('No monitors');
});

it.each([
  ['updated_at=null with the server reporting fresh', { updated_at: null, stale: false }],
  ['a local age greater than 900 seconds', { updated_at: '2026-08-07T11:44:59Z', stale: false }],
] as const)('never presents %s as healthy', (_label, freshness) => {
  const renderer = renderOverview(overview({ summary: { ...overview().summary, ...freshness } }));

  expect(text(renderer.root.findByProps({ 'data-testid': 'stale-summary' }))).toContain('Status data is stale');
  expect(renderer.root.findAllByProps({ 'data-testid': 'all-healthy' })).toHaveLength(0);
});

it('keeps a local age of exactly 900 seconds healthy', () => {
  const renderer = renderOverview(overview({ summary: { ...overview().summary, updated_at: '2026-08-07T11:45:00Z' } }));

  expect(renderer.root.findByProps({ 'data-testid': 'all-healthy' })).toBeTruthy();
  expect(renderer.root.findAllByProps({ 'data-testid': 'stale-summary' })).toHaveLength(0);
});

it('renders deterministic loading and error states while retaining contract-valid results', async () => {
  let rejectVisit!: (reason: Error) => void;
  const visit = jest.fn(() => new Promise<ReturnType<typeof overview>>((_, reject) => { rejectVisit = reject; }));
  const renderer = renderOverview(overview({ problems: [problem] }), { visit });
  const apiFilter = renderer.root.find((node) => node.type === 'input' && node.props.name === 'types[]' && node.props.value === 'api');

  await act(async () => {
    apiFilter.props.onChange();
    await Promise.resolve();
  });
  expect(renderer.root.findByProps({ 'data-testid': 'dashboard-loading' }).props.role).toBe('progressbar');
  expect(renderer.root.findByProps({ 'href': problem.detail_url })).toBeTruthy();

  await act(async () => {
    rejectVisit(new Error('network unavailable'));
    await Promise.resolve();
  });
  expect(renderer.root.findByProps({ 'data-testid': 'dashboard-error' }).props.role).toBe('alert');
  expect(renderer.root.findByProps({ 'href': problem.detail_url })).toBeTruthy();
});
