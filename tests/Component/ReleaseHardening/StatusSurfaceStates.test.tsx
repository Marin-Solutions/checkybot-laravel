import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
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
