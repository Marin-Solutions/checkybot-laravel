import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import type { StatusSummary } from '../../../mobile/src/contracts/monitor-domain.generated';
import { MemorySummaryCache, StatusScreen } from '../../../mobile/src/status/StatusScreen';

const healthy: StatusSummary = {
  counts: {
    servers: { healthy: 3, warn: 0, down: 0 },
    websites: { healthy: 5, warn: 0, down: 0 },
    apis: { healthy: 2, warn: 0, down: 0 },
  },
  updated_at: '2026-08-07T11:55:00Z',
  stale: false,
};
const problems: StatusSummary = {
  counts: {
    servers: { healthy: 7, warn: 0, down: 2 },
    websites: { healthy: 8, warn: 1, down: 0 },
    apis: { healthy: 9, warn: 0, down: 0 },
  },
  updated_at: '2026-08-07T11:55:00Z',
  stale: false,
};
const now = () => Date.parse('2026-08-07T12:00:00Z');

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => { resolve = done; });
  return { promise, resolve };
}

test('shows a progress state before the first response and then explicit all healthy copy', async () => {
  const pending = deferred<StatusSummary>();
  render(<StatusScreen api={{ getStatusSummary: jest.fn().mockReturnValue(pending.promise) }} now={now} />);
  expect(screen.getByTestId('status-loading')).toBeOnTheScreen();
  expect(screen.getByTestId('status-loading')).toHaveProp('accessibilityRole', 'progressbar');
  pending.resolve(healthy);
  expect(await screen.findByTestId('all-healthy')).toHaveTextContent('No warnings or outages right now.');
  expect(screen.getByText('Everything is healthy')).toBeOnTheScreen();
});

test('shows only non-healthy rows and their counts in problem state', async () => {
  render(<StatusScreen api={{ getStatusSummary: jest.fn().mockResolvedValue(problems) }} now={now} />);
  expect(await screen.findByTestId('problem-servers-down')).toHaveTextContent(/Servers · Down/);
  expect(screen.getByTestId('problem-servers-down')).toHaveTextContent(/2/);
  expect(screen.getByTestId('problem-websites-warn')).toHaveTextContent(/Websites · Warning/);
  expect(screen.queryByTestId('problem-apis-healthy')).not.toBeOnTheScreen();
  expect(screen.queryByText(/healthy: 9/i)).not.toBeOnTheScreen();
});

test('keeps cached problem data and last-synced age visible after a failed refresh', async () => {
  const getStatusSummary = jest.fn().mockResolvedValueOnce(problems).mockRejectedValueOnce(new Error('offline'));
  const cache = new MemorySummaryCache();
  render(<StatusScreen api={{ getStatusSummary }} cache={cache} now={now} />);
  await screen.findByTestId('problem-servers-down');
  fireEvent.press(screen.getByRole('button', { name: 'Refresh status' }));
  expect(await screen.findByTestId('offline-banner')).toHaveTextContent(/Showing your last synced status\./);
  expect(screen.getByTestId('last-synced')).toHaveTextContent('Last synced 5m ago');
  expect(screen.getByTestId('problem-servers-down')).toHaveTextContent(/2/);
  await waitFor(() => expect(getStatusSummary).toHaveBeenCalledTimes(2));
});
