import type { MonitorIdentity } from './contracts';

export type ApiMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
export type AssertionKind = 'status' | 'latency' | 'json_path';
export type AssertionOperator =
  | 'equals'
  | 'in'
  | 'less_than_or_equal'
  | 'exists'
  | 'not_null'
  | 'not_equals'
  | 'type'
  | 'non_empty';

export interface ApiAssertion {
  kind: AssertionKind;
  operator: AssertionOperator;
  path?: string;
  expected?: unknown;
}

export interface MaskedHeader {
  name: string;
  mask: string;
  has_value: boolean;
}

export interface ApiBuilderConfiguration {
  endpoint: string;
  method: ApiMethod;
  headers: MaskedHeader[];
  assertions: ApiAssertion[];
  version: number;
}

export interface ApiAssertionBuilderProps {
  monitor: MonitorIdentity;
  configuration: ApiBuilderConfiguration;
  sample: null;
}

export interface SamplePath {
  path: string;
  inferred_type: string;
  preview: string;
}

export interface ApiSample {
  mode: 'sample';
  status: number;
  latency_ms: number;
  json: unknown;
  paths: SamplePath[];
}

export type HeaderAction = 'preserve' | 'set' | 'remove';

export interface HeaderDraft {
  id: string;
  name: string;
  mask: string;
  hasValue: boolean;
  action: HeaderAction;
  replacement: string;
}

export interface AssertionDraft extends ApiAssertion {
  id: string;
}

export interface ApiErrorPayload {
  message?: string;
  manual_entry?: boolean;
  errors?: Record<string, string[]>;
  error?: {
    code: 'fetch_timeout' | 'fetch_failed' | 'non_json' | 'upstream_auth';
    message: string;
    upstream_status: number | null;
  };
}
