import type {
  LifecycleState,
  MonitorIdentity,
  MonitorType,
  Severity,
  StatusSummary,
} from '../../../../packages/contracts/generated/monitor-foundation';

export type { LifecycleState, MonitorIdentity, MonitorType, Severity, StatusSummary };

export type ProblemState = Exclude<LifecycleState, 'healthy'>;

export interface DashboardFilters {
  types: MonitorType[];
  states: ProblemState[];
  severities: Severity[];
  monitor_uuids: string[];
}

export interface Problem {
  identity: MonitorIdentity;
  state: ProblemState;
  severity: Severity;
  observed_at: string;
  detail_url: string;
}

export interface Maintenance {
  reason: string | null;
  ends_at: string | null;
  silenced: boolean;
  effective_scope: 'project' | 'global' | null;
}

export interface OverviewProps {
  filters: DashboardFilters;
  summary: StatusSummary;
  problems: Problem[];
  pagination: { per_page: number; next_cursor: string | null };
  maintenance: Maintenance;
}

export interface TimelineTransition {
  transition_id: string;
  from: LifecycleState;
  to: LifecycleState;
  severity: Severity;
  occurred_at: string;
  duration_seconds: number | null;
  reason_code: string | null;
  group_id: string | null;
  maintenance_suppressed: boolean;
}

export interface IncidentGroupMember {
  project_uuid: string;
  monitor_uuid: string;
  type: MonitorType;
}

export interface IncidentGroup {
  group_id: string;
  opened_at: string;
  closed_at: string | null;
  notification_thread_key: string;
  affected_monitors: IncidentGroupMember[];
}

export interface AnnotationSlot {
  key: string;
  value: string | null;
}

export interface MonitorDetailProps {
  monitor: {
    identity: MonitorIdentity;
    entered_at: string;
    current_state: LifecycleState;
  };
  timeline: {
    transitions: TimelineTransition[];
    incident_groups: IncidentGroup[];
    annotation_slots: AnnotationSlot[];
  };
}
