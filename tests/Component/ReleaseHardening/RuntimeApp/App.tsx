import React from 'react';
import { parseStatusSummaryResponse } from '../../../../mobile/src/contracts/monitor-domain.generated';
import { MemorySummaryCache, StatusScreen } from '../../../../mobile/src/status/StatusScreen';

const cache = new MemorySummaryCache();

const statusApi = {
  async getStatusSummary() {
    const response = await fetch('/api/status-summary', {
      headers: {
        Accept: 'application/json',
        Authorization: 'Bearer cbp_harness_status_read_token',
      },
    });
    if (!response.ok) throw new Error(`Status API returned HTTP ${response.status}`);
    return parseStatusSummaryResponse(await response.json()).data;
  },
};

export default function App() {
  return <StatusScreen api={statusApi} cache={cache} />;
}
