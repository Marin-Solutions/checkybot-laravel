import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';

import {
  HarnessApi,
  HarnessFixture,
  ProbeResponse,
  QueuedResponse,
  ReadyResponse,
} from '../../../e2e/harness-fixture/App';

const runId = 'a6ff58e0-f7d7-4ba5-82f0-65de4ad7b3ca';
const probeId = 'ca30d2ca-b092-40d5-a783-396720420b7f';
const acceptedAt = '2026-08-07T10:00:00.000Z';
const processedAt = '2026-08-07T10:00:01.000Z';

const ready = (overrides: Partial<ReadyResponse> = {}): ReadyResponse => ({
  app: 'ready',
  queue: 'ready',
  run_id: runId,
  ...overrides,
});

const queued = (): QueuedResponse => ({
  status: 'queued',
  probe_id: probeId,
  accepted_at: acceptedAt,
});

const processed = (status: ProbeResponse['status'] = 'processed'): ProbeResponse => ({
  status,
  probe_id: probeId,
  processed_at: status === 'processed' ? processedAt : null,
});

function api(overrides: Partial<HarnessApi> = {}): HarnessApi {
  return {
    ready: jest.fn().mockResolvedValue(ready()),
    create: jest.fn().mockResolvedValue(queued()),
    probe: jest.fn().mockResolvedValue(processed()),
    ...overrides,
  };
}

beforeEach(() => {
  jest.useRealTimers();
});

test('renders the backend-starting contract state while readiness reports starting', async () => {
  const harnessApi = api({
    ready: jest.fn().mockResolvedValue(ready({ app: 'starting', queue: 'starting' })),
  });

  render(<HarnessFixture api={harnessApi} readinessPollMs={60_000} />);

  expect(screen.getByTestId('backend-starting')).toHaveTextContent('Backend starting');
  await waitFor(() => expect(harnessApi.ready).toHaveBeenCalledTimes(1));
  expect(screen.getByText(`Run ID: ${runId}`)).toBeOnTheScreen();
});

test('renders ready after a successful readiness response', async () => {
  render(<HarnessFixture api={api()} />);

  expect(await screen.findByTestId('backend-ready')).toHaveTextContent('Backend ready');
  expect(screen.getByRole('button', { name: 'Create queue probe' })).toBeOnTheScreen();
  expect(screen.getByText(`Run ID: ${runId}`)).toBeOnTheScreen();
});

test('renders the queued response before polling for processing', async () => {
  const pendingProbe = new Promise<ProbeResponse>(() => undefined);
  const harnessApi = api({ probe: jest.fn().mockReturnValue(pendingProbe) });
  render(<HarnessFixture api={harnessApi} probePollMs={60_000} makeProbeId={() => probeId} />);

  fireEvent.press(await screen.findByRole('button', { name: 'Create queue probe' }));

  expect(await screen.findByText('Queue probe queued')).toBeOnTheScreen();
  expect(screen.getByText(probeId)).toBeOnTheScreen();
  expect(harnessApi.create).toHaveBeenCalledWith(probeId);
});

test('polls the declared GET response and renders a processed queue probe', async () => {
  const harnessApi = api({
    probe: jest.fn()
      .mockResolvedValueOnce(processed('queued'))
      .mockResolvedValueOnce(processed('processed')),
  });
  render(<HarnessFixture api={harnessApi} probePollMs={1} makeProbeId={() => probeId} />);

  fireEvent.press(await screen.findByRole('button', { name: 'Create queue probe' }));

  expect(await screen.findByText('Queue probe processed')).toBeOnTheScreen();
  expect(screen.getByText(`Processed at ${processedAt}`)).toBeOnTheScreen();
  expect(harnessApi.probe).toHaveBeenCalledTimes(2);
});

test('renders an API-error state for the declared failed readiness response', async () => {
  const harnessApi = api({
    ready: jest.fn().mockResolvedValue(ready({ app: 'failed', queue: 'failed' })),
  });
  render(<HarnessFixture api={harnessApi} />);

  expect(await screen.findByText('Harness API error')).toBeOnTheScreen();
  expect(screen.getByText('Backend runtime reported a failed state')).toBeOnTheScreen();
});
