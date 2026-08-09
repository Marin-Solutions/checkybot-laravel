import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { StatusAuthError } from '../../../mobile/src/api/CheckybotApiClient';
import type { StatusSummary } from '../../../mobile/src/contracts/monitor-domain.generated';
import { MemorySummaryCache, StatusScreen } from '../../../mobile/src/status/StatusScreen';

const now = () => Date.parse('2026-08-08T12:00:00Z');
const healthy: StatusSummary = {
  counts: {
    servers: { healthy: 4, warn: 0, down: 0 },
    websites: { healthy: 8, warn: 0, down: 0 },
    apis: { healthy: 3, warn: 0, down: 0 },
  },
  updated_at: '2026-08-08T11:55:00Z',
  stale: false,
};
const problems: StatusSummary = {
  counts: {
    servers: { healthy: 4, warn: 0, down: 2 },
    websites: { healthy: 8, warn: 1, down: 0 },
    apis: { healthy: 3, warn: 0, down: 0 },
  },
  updated_at: '2026-08-08T11:55:00Z',
  stale: false,
};

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => { resolve = done; });
  return { promise, resolve };
}

test('production status surface moves from first-load progress to an explicit all-healthy state', async () => {
  const pending = deferred<StatusSummary>();
  render(<StatusScreen api={{ getStatusSummary: jest.fn(() => pending.promise) }} now={now} />);

  expect(screen.getByTestId('status-loading')).toHaveProp('accessibilityRole', 'progressbar');
  expect(screen.queryByTestId('status-summary')).not.toBeOnTheScreen();

  pending.resolve(healthy);
  expect(await screen.findByTestId('all-healthy')).toHaveTextContent('No warnings or outages right now.');
  expect(screen.getByText('Everything is healthy')).toBeOnTheScreen();
  expect(screen.getByTestId('last-synced')).toHaveTextContent('Last synced 5m ago');
});

test('production problem state renders only nonzero warning/down rows', async () => {
  render(<StatusScreen api={{ getStatusSummary: jest.fn().mockResolvedValue(problems) }} now={now} />);

  expect(await screen.findByTestId('problem-servers-down')).toHaveTextContent(/Servers · Down.*2/);
  expect(screen.getByTestId('problem-websites-warn')).toHaveTextContent(/Websites · Warning.*1/);
  expect(screen.queryByTestId('problem-servers-warn')).not.toBeOnTheScreen();
  expect(screen.queryByTestId('problem-websites-down')).not.toBeOnTheScreen();
  expect(screen.queryByTestId('problem-apis-warn')).not.toBeOnTheScreen();
  expect(screen.queryByText(/healthy: 3/i)).not.toBeOnTheScreen();
});

test('refresh failure persistently keeps cached counts and last-sync age beside the offline alert', async () => {
  const getStatusSummary = jest.fn()
    .mockResolvedValueOnce(problems)
    .mockRejectedValueOnce(new Error('Status API transport failed'));
  render(<StatusScreen api={{ getStatusSummary }} cache={new MemorySummaryCache()} now={now} />);
  await screen.findByTestId('problem-servers-down');

  fireEvent.press(screen.getByRole('button', { name: 'Refresh status' }));

  expect(await screen.findByTestId('offline-banner')).toHaveProp('accessibilityRole', 'alert');
  expect(screen.getByText('Showing your last synced status.')).toBeOnTheScreen();
  expect(screen.getByText('Status API transport failed')).toBeOnTheScreen();
  expect(screen.getByTestId('problem-servers-down')).toHaveTextContent(/2/);
  expect(screen.getByTestId('last-synced')).toHaveTextContent('Last synced 5m ago');
  await waitFor(() => expect(getStatusSummary).toHaveBeenCalledTimes(2));
});

// --- Status freshness gate (docs/release-hardening.md): stale, unknown, error, and auth states
// --- must never render the green all-clear on the status surface.

const emptyCounts: StatusSummary['counts'] = {
  servers: { healthy: 0, warn: 0, down: 0 },
  websites: { healthy: 0, warn: 0, down: 0 },
  apis: { healthy: 0, warn: 0, down: 0 },
};

function expectNoHealthySemantics() {
  expect(screen.queryByTestId('all-healthy')).not.toBeOnTheScreen();
  expect(screen.queryByText('Everything is healthy')).not.toBeOnTheScreen();
  expect(screen.queryByText(/No warnings or outages right now/)).not.toBeOnTheScreen();
}

test.each([
  ['updated_at=null', { updated_at: null, stale: false }],
  ['server stale=true', { updated_at: '2026-08-08T11:55:00Z', stale: true }],
  ['local age greater than 900 seconds', { updated_at: '2026-08-08T11:44:59Z', stale: false }],
] as const)('%s renders the stale state and never the green all-clear', async (_label, freshness) => {
  const summary: StatusSummary = { ...healthy, ...freshness };
  render(<StatusScreen api={{ getStatusSummary: jest.fn().mockResolvedValue(summary) }} now={now} />);

  expect(await screen.findByTestId('stale-summary')).toHaveTextContent(/Current health cannot be confirmed/);
  expect(screen.getByText('Status not confirmed')).toBeOnTheScreen();
  expectNoHealthySemantics();
});

test('local age of exactly 900 seconds is still fresh enough to be healthy', async () => {
  const summary: StatusSummary = { ...healthy, updated_at: '2026-08-08T11:45:00Z' };
  render(<StatusScreen api={{ getStatusSummary: jest.fn().mockResolvedValue(summary) }} now={now} />);

  expect(await screen.findByTestId('all-healthy')).toBeOnTheScreen();
  expect(screen.queryByTestId('stale-summary')).not.toBeOnTheScreen();
});

test('a project with no monitors reads as empty rather than healthy', async () => {
  const summary: StatusSummary = { counts: emptyCounts, updated_at: '2026-08-08T11:55:00Z', stale: false };
  render(<StatusScreen api={{ getStatusSummary: jest.fn().mockResolvedValue(summary) }} now={now} />);

  expect(await screen.findByTestId('empty-project')).toHaveTextContent(/No monitors have been added/);
  expectNoHealthySemantics();
});

test('a transport failure over fresh healthy cache withdraws the all-clear', async () => {
  const getStatusSummary = jest.fn()
    .mockResolvedValueOnce(healthy)
    .mockRejectedValueOnce(new Error('Status API transport failed'));
  render(<StatusScreen api={{ getStatusSummary }} cache={new MemorySummaryCache()} now={now} />);
  await screen.findByTestId('all-healthy');

  fireEvent.press(screen.getByRole('button', { name: 'Refresh status' }));

  expect(await screen.findByTestId('offline-banner')).toHaveProp('accessibilityRole', 'alert');
  expect(screen.getByTestId('stale-summary')).toBeOnTheScreen();
  expect(screen.getByTestId('last-synced')).toHaveTextContent('Last synced 5m ago');
  expectNoHealthySemantics();
  await waitFor(() => expect(getStatusSummary).toHaveBeenCalledTimes(2));
});

test('a 401/403 surfaces as an auth state distinct from offline and withdraws the all-clear', async () => {
  const getStatusSummary = jest.fn()
    .mockResolvedValueOnce(healthy)
    .mockRejectedValueOnce(new StatusAuthError('Your session has expired. Sign in again to see live status.'));
  render(<StatusScreen api={{ getStatusSummary }} cache={new MemorySummaryCache()} now={now} />);
  await screen.findByTestId('all-healthy');

  fireEvent.press(screen.getByRole('button', { name: 'Refresh status' }));

  expect(await screen.findByTestId('auth-banner')).toHaveTextContent(/Sign in again/);
  expect(screen.queryByTestId('offline-banner')).not.toBeOnTheScreen();
  expect(screen.getByTestId('stale-summary')).toBeOnTheScreen();
  expectNoHealthySemantics();
});
