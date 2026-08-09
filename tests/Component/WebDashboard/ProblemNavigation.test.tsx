import React from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import type { Problem } from '../../../resources/js/Components/CheckybotDashboard/contracts';
import Overview from '../../../resources/js/Pages/CheckybotDashboard/Overview';
import { monitorUuid, overview, problem, secondMonitorUuid } from './fixtures';

const apiProblem: Problem = {
  ...problem,
  identity: { ...problem.identity, monitor_id: secondMonitorUuid, type: 'api' },
  state: 'warn',
  severity: 'warn',
  detail_url: `/checkybot/monitors/api/${secondMonitorUuid}`,
};

function input(renderer: TestRenderer.ReactTestRenderer, name: string, value: string) {
  return renderer.root.find((node) => node.type === 'input' && node.props.name === name && node.props.value === value);
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => { resolve = done; });
  return { promise, resolve };
}

function inertiaResponse(props: ReturnType<typeof overview>) {
  return {
    ok: true,
    json: async () => ({ component: 'CheckybotDashboard/Overview', props }),
  };
}

it('initializes normalized URL filters, preserves the notification UUID, and renders only server-returned problems', async () => {
  const initial = overview({
    filters: { types: ['website'], states: ['down'], severities: ['critical'], monitor_uuids: [monitorUuid] },
    problems: [problem],
  });
  const returned = overview({
    filters: { types: ['website', 'api'], states: ['down'], severities: ['critical'], monitor_uuids: [monitorUuid] },
    problems: [apiProblem],
  });
  const visit = jest.fn().mockResolvedValue(returned);
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<Overview {...initial} formatDate={(value) => value} visit={visit} />); });

  expect(input(renderer, 'types[]', 'website').props.checked).toBe(true);
  expect(input(renderer, 'states[]', 'down').props.checked).toBe(true);
  expect(input(renderer, 'severities[]', 'critical').props.checked).toBe(true);
  expect(renderer.root.findByProps({ 'data-testid': 'monitor-filter-summary' })).toBeTruthy();

  await act(async () => {
    await input(renderer, 'types[]', 'api').props.onChange();
  });

  const visited = new URL(visit.mock.calls[0][0], 'https://checkybot.test');
  expect(visited.searchParams.getAll('types[]')).toEqual(['website', 'api']);
  expect(visited.searchParams.getAll('states[]')).toEqual(['down']);
  expect(visited.searchParams.getAll('severities[]')).toEqual(['critical']);
  expect(visited.searchParams.getAll('monitor_uuids[]')).toEqual([monitorUuid]);
  expect(renderer.root.findAllByProps({ href: problem.detail_url })).toHaveLength(0);
  expect(renderer.root.findByProps({ href: apiProblem.detail_url })).toBeTruthy();
  act(() => renderer.unmount());
});

it('retains notification monitor filtering across real history restoration and reload props', async () => {
  const filtered = overview({
    filters: { types: ['website'], states: ['down'], severities: [], monitor_uuids: [monitorUuid] },
    problems: [problem],
  });
  const fetchMock = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ component: 'CheckybotDashboard/Overview', props: filtered }),
  });
  Object.defineProperty(globalThis, 'fetch', { configurable: true, writable: true, value: fetchMock });
  window.history.replaceState({}, '', `/checkybot?types[]=website&states[]=down&monitor_uuids[]=${monitorUuid}`);

  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<Overview {...filtered} formatDate={(value) => value} />); });
  window.history.replaceState({}, '', `/checkybot?states[]=down&monitor_uuids[]=${monitorUuid}`);
  await act(async () => {
    window.dispatchEvent(new PopStateEvent('popstate'));
    await new Promise((resolve) => setTimeout(resolve, 0));
  });

  expect(fetchMock).toHaveBeenCalledWith(
    expect.stringContaining(`monitor_uuids[]=${monitorUuid}`),
    expect.objectContaining({ headers: expect.objectContaining({ 'X-Inertia': 'true' }) }),
  );
  expect(renderer.root.findByProps({ 'data-testid': 'monitor-filter-summary' })).toBeTruthy();

  act(() => {
    renderer.update(<Overview {...filtered} formatDate={(value) => value} />);
  });
  expect(input(renderer, 'types[]', 'website').props.checked).toBe(true);
  expect(renderer.root.findByProps({ href: problem.detail_url })).toBeTruthy();
  act(() => renderer.unmount());
});

it('keeps a newer back-navigation restore authoritative when an older filter response resolves late', async () => {
  const initial = overview({
    filters: { types: ['website'], states: ['down'], severities: [], monitor_uuids: [monitorUuid] },
    problems: [problem],
  });
  const staleFilterResult = overview({
    filters: { types: ['website', 'api'], states: ['down'], severities: [], monitor_uuids: [monitorUuid] },
    problems: [apiProblem],
  });
  const restored = overview({
    filters: { types: ['website'], states: ['down'], severities: [], monitor_uuids: [monitorUuid] },
    problems: [problem],
  });
  const oldFilterRequest = deferred<ReturnType<typeof inertiaResponse>>();
  const restoreRequest = deferred<ReturnType<typeof inertiaResponse>>();
  const fetchMock = jest.fn()
    .mockReturnValueOnce(oldFilterRequest.promise)
    .mockReturnValueOnce(restoreRequest.promise);
  Object.defineProperty(globalThis, 'fetch', { configurable: true, writable: true, value: fetchMock });
  const restoredUrl = `/checkybot?types[]=website&states[]=down&monitor_uuids[]=${monitorUuid}`;
  window.history.replaceState({}, '', restoredUrl);

  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<Overview {...initial} formatDate={(value) => value} />); });

  await act(async () => {
    input(renderer, 'types[]', 'api').props.onChange();
    await Promise.resolve();
  });
  expect(fetchMock).toHaveBeenCalledTimes(1);
  expect(new URL(fetchMock.mock.calls[0][0], 'https://checkybot.test').searchParams.getAll('monitor_uuids[]')).toEqual([monitorUuid]);
  expect(input(renderer, 'types[]', 'api').props.checked).toBe(true);

  window.history.replaceState({}, '', restoredUrl);
  await act(async () => {
    window.dispatchEvent(new PopStateEvent('popstate'));
    await Promise.resolve();
  });
  expect(fetchMock).toHaveBeenCalledTimes(2);
  expect(new URL(fetchMock.mock.calls[1][0], 'https://checkybot.test').searchParams.getAll('monitor_uuids[]')).toEqual([monitorUuid]);

  await act(async () => {
    restoreRequest.resolve(inertiaResponse(restored));
    await Promise.resolve();
  });
  expect(input(renderer, 'types[]', 'api').props.checked).toBe(false);
  expect(renderer.root.findByProps({ href: problem.detail_url })).toBeTruthy();
  expect(new URLSearchParams(window.location.search).getAll('types[]')).toEqual(['website']);
  expect(new URLSearchParams(window.location.search).getAll('monitor_uuids[]')).toEqual([monitorUuid]);

  await act(async () => {
    oldFilterRequest.resolve(inertiaResponse(staleFilterResult));
    await Promise.resolve();
  });
  expect(input(renderer, 'types[]', 'api').props.checked).toBe(false);
  expect(renderer.root.findAllByProps({ href: apiProblem.detail_url })).toHaveLength(0);
  expect(renderer.root.findByProps({ href: problem.detail_url })).toBeTruthy();
  expect(new URLSearchParams(window.location.search).getAll('types[]')).toEqual(['website']);
  expect(new URLSearchParams(window.location.search).getAll('monitor_uuids[]')).toEqual([monitorUuid]);
  expect(renderer.root.findAllByProps({ 'data-testid': 'dashboard-loading' })).toHaveLength(0);
  act(() => renderer.unmount());
});

it('does not commit page or history state after an in-flight navigation unmounts', async () => {
  const initial = overview({
    filters: { types: ['website'], states: ['down'], severities: [], monitor_uuids: [monitorUuid] },
    problems: [problem],
  });
  const request = deferred<ReturnType<typeof overview>>();
  const visit = jest.fn().mockReturnValue(request.promise);
  const initialUrl = `/checkybot?types[]=website&states[]=down&monitor_uuids[]=${monitorUuid}`;
  window.history.replaceState({}, '', initialUrl);
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<Overview {...initial} formatDate={(value) => value} visit={visit} />); });

  await act(async () => {
    input(renderer, 'types[]', 'api').props.onChange();
    await Promise.resolve();
  });
  act(() => renderer.unmount());
  await act(async () => {
    request.resolve(overview({ ...initial, filters: { ...initial.filters, types: ['website', 'api'] } }));
    await Promise.resolve();
  });

  expect(new URLSearchParams(window.location.search).getAll('types[]')).toEqual(['website']);
  expect(new URLSearchParams(window.location.search).getAll('monitor_uuids[]')).toEqual([monitorUuid]);
});

