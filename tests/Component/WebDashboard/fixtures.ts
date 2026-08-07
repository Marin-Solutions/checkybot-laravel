import type { MonitorDetailProps, OverviewProps, Problem } from '../../../resources/js/Components/CheckybotDashboard/contracts';

export const monitorUuid = '11111111-1111-4111-8111-111111111111';
export const secondMonitorUuid = '22222222-2222-4222-8222-222222222222';

export const problem: Problem = {
  identity: { project_id: 'project-a', monitor_id: monitorUuid, type: 'website' },
  state: 'down',
  severity: 'critical',
  observed_at: '2026-08-07T11:58:00Z',
  detail_url: `/checkybot/monitors/website/${monitorUuid}`,
};

export function overview(overrides: Partial<OverviewProps> = {}): OverviewProps {
  return {
    filters: { types: [], states: ['warn', 'down', 'recovering'], severities: [], monitor_uuids: [] },
    summary: {
      counts: {
        servers: { healthy: 3, warn: 0, down: 0 },
        websites: { healthy: 4, warn: 0, down: 0 },
        apis: { healthy: 2, warn: 0, down: 0 },
      },
      updated_at: '2026-08-07T11:59:00Z',
      stale: false,
    },
    problems: [],
    pagination: { per_page: 25, next_cursor: null },
    maintenance: { silenced: false, effective_scope: null, ends_at: null, reason: null },
    ...overrides,
  };
}

export function detail(overrides: Partial<MonitorDetailProps> = {}): MonitorDetailProps {
  return {
    monitor: {
      identity: { project_id: 'project-a', monitor_id: monitorUuid, type: 'website' },
      entered_at: '2026-08-07T11:00:00Z',
      current_state: 'recovering',
    },
    timeline: {
      transitions: [
        {
          transition_id: 'transition-late',
          from: 'down',
          to: 'recovering',
          severity: 'warn',
          occurred_at: '2026-08-07T11:00:00Z',
          duration_seconds: null,
          reason_code: null,
          group_id: 'group-a',
          maintenance_suppressed: true,
        },
        {
          transition_id: 'transition-early',
          from: 'healthy',
          to: 'down',
          severity: 'critical',
          occurred_at: '2026-08-07T10:58:35Z',
          duration_seconds: 85,
          reason_code: 'connection_timeout',
          group_id: 'group-a',
          maintenance_suppressed: false,
        },
      ],
      incident_groups: [{
        group_id: 'group-a',
        opened_at: '2026-08-07T10:58:35Z',
        closed_at: null,
        notification_thread_key: 'incident:project-a:group-a',
        affected_monitors: [
          { project_uuid: 'project-a', monitor_uuid: monitorUuid, type: 'website' },
          { project_uuid: 'project-a', monitor_uuid: secondMonitorUuid, type: 'api' },
        ],
      }],
      annotation_slots: [
        { key: 'root_cause', value: null },
        { key: 'customer_impact', value: 'Checkout requests were delayed.' },
      ],
    },
    ...overrides,
  };
}
