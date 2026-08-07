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
export interface RedactionIncident { contract_version: typeof MONITOR_FOUNDATION_VERSION; incident_id: string; log_lines: string[] }
export interface CheckDefinition { name: string; url: string; interval: string; [key: string]: unknown }
export interface CheckSyncPayload { contract_version: typeof CHECK_SYNC_VERSION; uptime: CheckDefinition[]; ssl: CheckDefinition[]; api: CheckDefinition[]; dead_links: CheckDefinition[]; open_graph: CheckDefinition[] }
export type FoundationEvent =
  | { operation_id: string; event_type: 'monitor.transitioned'; payload: MonitorTransition }
  | { operation_id: string; event_type: 'contract.check_sync.probed'; payload: CheckSyncPayload }
  | { operation_id: string; event_type: 'incident.redaction.probed'; payload: RedactionIncident };
