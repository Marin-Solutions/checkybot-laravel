import React from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import MonitorDetail from '../../../resources/js/Pages/CheckybotDashboard/MonitorDetail';
import type { MonitorDetailProps } from '../../../resources/js/Components/CheckybotDashboard/contracts';

const projectUuid = '11111111-1111-4111-8111-111111111111';
const monitorUuid = '22222222-2222-4222-8222-222222222222';
const probableCause = 'Repeated upstream timeouts saturated the PHP-FPM worker pool, exhausting available workers and causing the confirmed outage.';

function props(rootCause: string | null): MonitorDetailProps {
  return {
    monitor: {
      identity: { project_id: projectUuid, monitor_id: monitorUuid, type: 'server' },
      entered_at: '2026-08-08T12:02:00Z',
      current_state: 'down',
    },
    timeline: {
      transitions: [{
        transition_id: 'transition-down',
        from: 'warn',
        to: 'down',
        severity: 'critical',
        occurred_at: '2026-08-08T12:02:00Z',
        duration_seconds: null,
        reason_code: 'static-threshold-confirmed',
        group_id: 'incident-group-a',
        maintenance_suppressed: false,
      }],
      incident_groups: [{
        group_id: 'incident-group-a',
        opened_at: '2026-08-08T12:02:00Z',
        closed_at: null,
        notification_thread_key: 'incident:project:group-a',
        affected_monitors: [{ project_uuid: projectUuid, monitor_uuid: monitorUuid, type: 'server' }],
      }],
      annotation_slots: [
        {
          key: 'root_cause',
          value: rootCause,
          // Deliberately contract-invalid extras prove the shared renderer does not leak metadata.
          provider_name: 'Forbidden Provider Name',
          model: 'forbidden-model',
          prompt: 'forbidden prompt body',
          raw_log_snippet: 'forbidden raw log snippet',
          credential: 'forbidden credential',
          input_tokens: 987,
          billed_microusd: 654,
          failure_diagnostic: 'forbidden failure detail',
        } as { key: string; value: string | null },
        { key: 'customer_impact', value: 'Checkout requests remained delayed.' },
      ],
    },
  };
}

function render(rootCause: string | null) {
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(<MonitorDetail {...props(rootCause)} formatDate={(value) => value} />);
  });
  return renderer;
}

function visibleText(renderer: TestRenderer.ReactTestRenderer): string {
  const collect = (node: unknown): string => {
    if (typeof node === 'string' || typeof node === 'number') return String(node);
    if (Array.isArray(node)) return node.map(collect).join(' ');
    if (node && typeof node === 'object' && 'children' in node) {
      return collect((node as { children?: unknown }).children ?? []);
    }
    return '';
  };
  return collect(renderer.toJSON()).replace(/\s+/g, ' ').trim();
}

it('renders a present root cause as one readable safely wrapping provider-neutral paragraph', () => {
  const renderer = render(probableCause);
  const annotationList = renderer.root.findByProps({ 'data-testid': 'annotation-slots' });
  const terms = annotationList.findAllByType('dt');
  const values = annotationList.findAllByType('dd');
  const rootCauseIndex = terms.findIndex((term) => term.children.join('') === 'Root Cause');
  const rootCauseValue = values[rootCauseIndex];

  expect(rootCauseIndex).toBeGreaterThanOrEqual(0);
  expect(rootCauseValue.children).toEqual([probableCause]);
  expect(rootCauseValue.props.className).toContain('whitespace-pre-wrap');
  expect(rootCauseValue.props.className).not.toContain('whitespace-nowrap');
  expect(rootCauseValue.findAllByType('br')).toHaveLength(0);
  expect(visibleText(renderer)).toContain(`Root Cause ${probableCause}`);

  for (const forbidden of [
    'Forbidden Provider Name', 'forbidden-model', 'forbidden prompt body',
    'forbidden raw log snippet', 'forbidden credential', '987', '654',
    'forbidden failure detail',
  ]) {
    expect(visibleText(renderer)).not.toContain(forbidden);
  }
});

it.each([
  'never-enabled',
  'disabled',
  'skipped',
  'failed',
  'not-yet-completed',
])('renders the explicit unavailable state for a null root cause (%s)', () => {
  const renderer = render(null);
  const unavailable = renderer.root.findByProps({ 'aria-label': 'Root Cause unavailable' });

  expect(unavailable.children).toEqual(['Unavailable']);
  expect(visibleText(renderer)).toContain('Root Cause Unavailable');
  expect(visibleText(renderer)).not.toContain(probableCause);
  expect(renderer.root.findAllByProps({ role: 'status' })).toHaveLength(0);
  expect(renderer.root.findAllByProps({ role: 'progressbar' })).toHaveLength(0);
  expect(renderer.root.findAllByProps({ role: 'alert' })).toHaveLength(0);

  // Provider-neutral siblings and the inherited timeline stay intact in every absent state.
  expect(visibleText(renderer)).toContain('Customer Impact Checkout requests remained delayed.');
  expect(visibleText(renderer)).toContain('Warn → Down');
  expect(renderer.root.findAllByProps({ 'data-occurred-at': '2026-08-08T12:02:00Z' })).toHaveLength(1);
  expect(renderer.root.findAll((node) => node.type === 'div' && node.props['data-group-id'] === 'incident-group-a')).toHaveLength(1);
});
