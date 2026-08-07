import { parseMonitorFilter, parseSeverity, type MonitorFilter, type Severity } from '../contracts/monitor-domain.generated';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export interface NotificationLinkData {
  route: 'problems';
  projectUuid: string;
  groupId: string;
  monitorUuids: string[];
  phase: 'incident' | 'recovery';
  severity: Severity;
}

export interface ProblemsRoute {
  pathname: '/problems';
  params: {
    projectUuid: string;
    groupUuid?: string;
    monitorUuids?: string;
    states?: string;
  };
  filter: MonitorFilter;
}

export function notificationProblemsRoute(value: unknown, activeProjectUuid: string): ProblemsRoute | null {
  if (!isRecord(value) || value.route !== 'problems' || value.projectUuid !== activeProjectUuid
    || !UUID.test(activeProjectUuid) || !UUID.test(String(value.groupId))
    || !Array.isArray(value.monitorUuids) || value.monitorUuids.length === 0
    || !['incident', 'recovery'].includes(String(value.phase))) return null;
  const monitorUuids = value.monitorUuids.map(String);
  if (monitorUuids.some((uuid) => !UUID.test(uuid)) || new Set(monitorUuids).size !== monitorUuids.length) return null;
  let severity: Severity;
  try {
    severity = parseSeverity(value.severity);
  } catch {
    return null;
  }
  const filter = parseMonitorFilter({ types: ['server', 'website', 'api'], states: ['warn', 'down'], severities: [severity] });
  return {
    pathname: '/problems',
    params: {
      projectUuid: activeProjectUuid,
      groupUuid: String(value.groupId),
      monitorUuids: monitorUuids.join(','),
    },
    filter,
  };
}

export function widgetProblemsRoute(url: string, activeProjectUuid: string): ProblemsRoute | null {
  try {
    const parsed = new URL(url);
    const projectUuid = parsed.searchParams.get('projectUuid');
    const states = parsed.searchParams.get('states');
    if (parsed.protocol !== 'checkybot:' || parsed.hostname !== 'problems' || projectUuid !== activeProjectUuid
      || states !== 'warn,down' || !UUID.test(activeProjectUuid)) return null;
    return {
      pathname: '/problems',
      params: { projectUuid: activeProjectUuid, states: 'warn,down' },
      filter: parseMonitorFilter({ types: ['server', 'website', 'api'], states: ['warn', 'down'], severities: ['warn', 'critical'] }),
    };
  } catch {
    return null;
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
