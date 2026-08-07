import React from 'react';
import TestRenderer, { act, type ReactTestInstance } from 'react-test-renderer';
import ApiAssertionBuilder, { type BuilderRequest } from '../../../resources/js/Pages/CheckybotDashboard/ApiAssertionBuilder';
import { builderConfiguration, builderProps, response } from './apiBuilderFixtures';

function text(node: ReactTestInstance | TestRenderer.ReactTestRenderer): string {
  const json = 'toJSON' in node ? node.toJSON() : node;
  const collect = (value: unknown): string => {
    if (typeof value === 'string' || typeof value === 'number') return String(value);
    if (Array.isArray(value)) return value.map(collect).join(' ');
    if (value && typeof value === 'object' && 'children' in value) return collect((value as { children?: unknown }).children ?? []);
    return '';
  };
  return collect(json).replace(/\s+/g, ' ').trim();
}

function button(root: ReactTestInstance, label: string) {
  return root.find((node) => node.type === 'button' && text(node) === label);
}

const failures = [
  ['fetch_timeout', 504, { error: { code: 'fetch_timeout', message: 'The upstream request timed out.', upstream_status: null }, manual_entry: true }],
  ['fetch_failed', 502, { error: { code: 'fetch_failed', message: 'The upstream request could not be completed.', upstream_status: null }, manual_entry: true }],
  ['non_json', 502, { error: { code: 'non_json', message: 'The upstream response was not JSON.', upstream_status: 200 }, manual_entry: true }],
  ['upstream_auth', 502, { error: { code: 'upstream_auth', message: 'The upstream rejected authentication.', upstream_status: 401 }, manual_entry: true }],
  ['configuration_conflict', 409, { message: 'The builder configuration changed; reload before fetching another sample.', manual_entry: true }],
  ['validation_failed', 422, { message: 'The given data was invalid.', errors: { endpoint: ['The endpoint is unsafe.'] }, manual_entry: true }],
] as const;

it.each(failures)('retains every draft and manual controls after %s', async (code, status, payload) => {
  const request: BuilderRequest = () => response(status, payload);
  const props = builderProps(builderConfiguration({
    assertions: [
      { kind: 'json_path', path: '$.draft.value', operator: 'equals', expected: 'unsaved' },
      { kind: 'latency', operator: 'less_than_or_equal', expected: 812 },
    ],
  }));
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<ApiAssertionBuilder {...props} request={request} />); });
  act(() => renderer.root.findByProps({ 'aria-label': 'Endpoint URL' }).props.onChange({ currentTarget: { value: 'https://draft.example.test/pending' } }));
  act(() => button(renderer.root, 'Fetch live sample').props.onClick());
  await act(async () => { await Promise.resolve(); await Promise.resolve(); });

  const alert = renderer.root.findByProps({ 'data-testid': 'sample-error' });
  expect(text(alert)).toContain(code);
  expect(text(alert)).toContain(payload.error?.message ?? payload.message);
  expect(renderer.root.findByProps({ 'aria-label': 'Endpoint URL' }).props.value).toBe('https://draft.example.test/pending');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 manual JSON path' }).props.value).toBe('$.draft.value');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 expected value' }).props.value).toBe('unsaved');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 2 expected value' }).props.value).toBe('812');
  expect(text(renderer)).toContain('[REDACTED]');
  expect(button(renderer.root, 'Save configuration').props.disabled).toBe(false);
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 manual JSON path' }).props.disabled).not.toBe(true);
}/* AC-web-dashboard-api-builder-14 */);
