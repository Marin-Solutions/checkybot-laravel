// Generated from monitor-foundation.schema.json. Do not edit.
export const MONITOR_FOUNDATION_VERSION = 'monitor-foundation.v1' as const;
export const CHECK_SYNC_VERSION = 'check-sync.v1' as const;
export type MonitorType = 'server' | 'website' | 'api';
export type LifecycleState = 'healthy' | 'warn' | 'down' | 'recovering';
export type Severity = 'warn' | 'critical';
export interface MonitorIdentity { project_id: string; monitor_id: string; type: MonitorType }
export interface MonitorFilter { types: MonitorType[]; states: LifecycleState[]; severities: Severity[] }
export interface MonitorTransition { contract_version: typeof MONITOR_FOUNDATION_VERSION; identity: MonitorIdentity; from_state: LifecycleState; to_state: LifecycleState; severity: Severity; filter: MonitorFilter; occurred_at: string }
export interface StatusCounts { healthy: number; warn: number; down: number }
export interface StatusSummary { counts: { servers: StatusCounts; websites: StatusCounts; apis: StatusCounts }; updated_at: string | null; stale: boolean }
export interface StatusSummaryResponse { data: StatusSummary }
export async function getStatusSummary(token: string, baseUrl = ''): Promise<StatusSummaryResponse> {
  const response = await fetch(`${baseUrl}/api/status-summary`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  const body = await response.json() as StatusSummaryResponse | { message?: string };
  if (!response.ok) throw new Error('message' in body ? body.message : `Status summary returned HTTP ${response.status}`);
  return body as StatusSummaryResponse;
}
export interface RedactionIncident { contract_version: typeof MONITOR_FOUNDATION_VERSION; incident_id: string; log_lines: string[] }
export interface CheckDefinition { name: string; url: string; interval: string; [key: string]: unknown }
export interface CheckSyncPayload { contract_version: typeof CHECK_SYNC_VERSION; uptime: CheckDefinition[]; ssl: CheckDefinition[]; api: CheckDefinition[]; dead_links: CheckDefinition[]; open_graph: CheckDefinition[]; domain_expiry: CheckDefinition[]; response_time_budget: CheckDefinition[] }
export type FoundationEvent =
  | { operation_id: string; event_type: 'monitor.transitioned'; payload: MonitorTransition }
  | { operation_id: string; event_type: 'contract.check_sync.probed'; payload: CheckSyncPayload }
  | { operation_id: string; event_type: 'incident.redaction.probed'; payload: RedactionIncident };
