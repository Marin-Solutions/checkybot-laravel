import type {
  ApiAssertionBuilderProps,
  ApiBuilderConfiguration,
  ApiSample,
} from '../../../resources/js/Components/CheckybotDashboard/api-builder-contracts';

export const apiMonitorUuid = '33333333-3333-4333-8333-333333333333';

export function builderConfiguration(overrides: Partial<ApiBuilderConfiguration> = {}): ApiBuilderConfiguration {
  return {
    endpoint: 'https://api.example.test/v1/orders',
    method: 'GET',
    headers: [{ name: 'Authorization', mask: '[REDACTED]', has_value: true }],
    assertions: [
      { kind: 'status', operator: 'equals', expected: 200 },
      { kind: 'latency', operator: 'less_than_or_equal', expected: 750 },
    ],
    version: 4,
    ...overrides,
  };
}

export function builderProps(configuration = builderConfiguration()): ApiAssertionBuilderProps {
  return {
    monitor: { project_id: 'project-a', monitor_id: apiMonitorUuid, type: 'api' },
    configuration,
    sample: null,
  };
}

export const liveSample: ApiSample = {
  mode: 'sample',
  status: 200,
  latency_ms: 42,
  json: { data: [{ id: 19, state: 'paid' }], meta: { count: 1 } },
  paths: [
    { path: '$.data[0].id', inferred_type: 'integer', preview: '19' },
    { path: '$.data[0].state', inferred_type: 'string', preview: 'paid' },
    { path: '$.meta.count', inferred_type: 'integer', preview: '1' },
  ],
};

export function response(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  });
}
