import React from 'react';
import TestRenderer, { act, type ReactTestInstance } from 'react-test-renderer';
import ApiAssertionBuilder, { type BuilderRequest } from '../../../resources/js/Pages/CheckybotDashboard/ApiAssertionBuilder';
import { builderProps, liveSample, response } from './apiBuilderFixtures';

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

it('renders a live bounded sample and keyboard-picks a canonical path without changing unrelated drafts', async () => {
  const request = jest.fn<ReturnType<BuilderRequest>, Parameters<BuilderRequest>>(() => response(200, { data: liveSample }));
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<ApiAssertionBuilder {...builderProps()} request={request} />); });

  const endpoint = renderer.root.findByProps({ 'aria-label': 'Endpoint URL' });
  act(() => endpoint.props.onChange({ currentTarget: { value: 'https://draft.example.test/unsaved' } }));
  act(() => button(renderer.root, 'Fetch live sample').props.onClick());
  await act(async () => { await Promise.resolve(); await Promise.resolve(); });

  expect(text(renderer.root.findByProps({ 'data-testid': 'sample-status' }))).toBe('200');
  expect(text(renderer.root.findByProps({ 'data-testid': 'sample-latency' }))).toBe('42 ms');
  expect(text(renderer.root.findByProps({ 'data-testid': 'sample-json' }))).toContain('"state": "paid"');
  const paths = renderer.root.findAll((node) => node.type === 'button' && node.props.role === 'treeitem');
  expect(paths.map((path) => path.props['data-path'])).toEqual(['$.data[0].id', '$.data[0].state', '$.meta.count']);

  act(() => paths[0].props.onKeyDown({ key: 'ArrowDown', preventDefault: jest.fn() }));
  expect(renderer.root.findByProps({ 'data-path': '$.data[0].state' }).props.tabIndex).toBe(0);
  act(() => renderer.root.findByProps({ 'data-path': '$.data[0].state' }).props.onKeyDown({ key: 'Enter', preventDefault: jest.fn() }));

  expect(renderer.root.findByProps({ 'aria-label': 'Endpoint URL' }).props.value).toBe('https://draft.example.test/unsaved');
  expect(renderer.root.findAll((node) => node.type === 'fieldset' && node.props['data-assertion-row'] !== undefined)).toHaveLength(3);
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 3 manual JSON path' }).props.value).toBe('$.data[0].state');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 expected value' }).props.value).toBe('200');
}/* AC-web-dashboard-api-builder-12 */);

it('inserts a keyboard-selected path into the chosen existing row in place', async () => {
  const props = builderProps({
    ...builderProps().configuration,
    assertions: [
      { kind: 'json_path', operator: 'exists', path: '$.draft' },
      { kind: 'status', operator: 'equals', expected: 204 },
    ],
  });
  const request: BuilderRequest = () => response(200, { data: liveSample });
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<ApiAssertionBuilder {...props} request={request} />); });
  act(() => button(renderer.root, 'Fetch live sample').props.onClick());
  await act(async () => { await Promise.resolve(); await Promise.resolve(); });

  act(() => button(renderer.root, 'Choose from sample').props.onClick());
  act(() => renderer.root.findByProps({ 'data-path': '$.data[0].id' }).props.onKeyDown({ key: 'Enter', preventDefault: jest.fn() }));

  expect(renderer.root.findAll((node) => node.type === 'fieldset' && node.props['data-assertion-row'] !== undefined)).toHaveLength(2);
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 manual JSON path' }).props.value).toBe('$.data[0].id');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 2 expected value' }).props.value).toBe('204');
}/* AC-web-dashboard-api-builder-12 */);
