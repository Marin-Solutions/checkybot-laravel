import React from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import MonitorDetail from '../../../resources/js/Pages/CheckybotDashboard/MonitorDetail';
import { detail, monitorUuid, secondMonitorUuid } from './fixtures';

function text(renderer: TestRenderer.ReactTestRenderer): string {
  const collect = (value: unknown): string => {
    if (typeof value === 'string' || typeof value === 'number') return String(value);
    if (Array.isArray(value)) return value.map(collect).join(' ');
    if (value && typeof value === 'object' && 'children' in value) return collect((value as { children?: unknown }).children ?? []);
    return '';
  };
  return collect(renderer.toJSON()).replace(/\s+/g, ' ').trim();
}

function renderDetail(props = detail()) {
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(<MonitorDetail {...props} formatDate={(value) => `LOCAL ${value}`} />);
  });
  return renderer;
}

it('orders transitions chronologically and labels lifecycle, severity, durations, and suppression', () => {
  const renderer = renderDetail();
  const events = renderer.root.findAll((node) => Boolean(node.props['data-occurred-at']));
  expect(events.map((event) => event.props['data-occurred-at'])).toEqual([
    '2026-08-07T10:58:35Z',
    '2026-08-07T11:00:00Z',
  ]);
  expect(text(renderer)).toContain('Healthy → Down');
  expect(text(renderer)).toContain('Critical severity');
  expect(text(renderer)).toContain('Completed · 1m 25s');
  expect(text(renderer)).toContain('Down → Recovering');
  expect(text(renderer)).toContain('Warning severity');
  expect(text(renderer)).toContain('Current · open');
  expect(text(renderer)).toContain('Maintenance suppressed');
  expect(text(renderer)).toContain('Not maintenance suppressed');
});

it('renders incident-group members and distinguishes unavailable annotations from present text', () => {
  const renderer = renderDetail();
  expect(text(renderer)).toContain(`Website · ${monitorUuid}`);
  expect(text(renderer)).toContain(`Api · ${secondMonitorUuid}`);
  expect(text(renderer)).toContain('Root Cause Unavailable');
  expect(text(renderer)).toContain('Customer Impact Checkout requests were delayed.');
  const unavailable = renderer.root.findByProps({ 'aria-label': 'Root Cause unavailable' });
  expect(unavailable.children).toEqual(['Unavailable']);
});

it('renders an explicit empty timeline and empty group state', () => {
  const props = detail({ timeline: { transitions: [], incident_groups: [], annotation_slots: [] } });
  const renderer = renderDetail(props);
  expect(renderer.root.findByProps({ 'data-testid': 'empty-timeline' })).toBeTruthy();
  expect(text(renderer)).toContain('No state transitions have been recorded');
  expect(text(renderer)).toContain('No incident groups are associated');
});
