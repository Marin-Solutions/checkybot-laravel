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

async function settle() {
  await act(async () => { await Promise.resolve(); await Promise.resolve(); });
}

it('adds, reorders, edits, removes, maps row errors, retains stale drafts, and accepts only normalized save data', async () => {
  const normalized = builderConfiguration({
    endpoint: 'https://normalized.example.test/v2',
    method: 'PATCH',
    headers: [{ name: 'Authorization', mask: '[REDACTED]', has_value: true }],
    assertions: [
      { kind: 'json_path', path: '$.edited', operator: 'exists' },
      { kind: 'latency', operator: 'less_than_or_equal', expected: 925 },
    ],
    version: 5,
  });
  const request = jest.fn<ReturnType<BuilderRequest>, Parameters<BuilderRequest>>()
    .mockImplementationOnce(() => response(422, {
      message: 'The given data was invalid.',
      errors: { 'assertions.1.expected': ['Latency must be within the accepted range.'] },
    }))
    .mockImplementationOnce(() => response(409, { message: 'The builder configuration changed; reload before saving.' }))
    .mockImplementationOnce(() => response(200, { data: normalized }));
  const reload = jest.fn();
  const props = builderProps(builderConfiguration({ assertions: [] }));
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<ApiAssertionBuilder {...props} reload={reload} request={request} />); });

  act(() => button(renderer.root, 'Add status assertion').props.onClick());
  act(() => button(renderer.root, 'Add latency assertion').props.onClick());
  act(() => button(renderer.root, 'Add JSON path assertion').props.onClick());
  expect(renderer.root.findAll((node) => node.type === 'fieldset' && node.props['data-assertion-row'] !== undefined)).toHaveLength(3);

  act(() => renderer.root.findByProps({ 'aria-label': 'Assertion 1 expected value' }).props.onChange({ currentTarget: { value: '201' } }));
  act(() => renderer.root.findByProps({ 'aria-label': 'Assertion 2 expected value' }).props.onChange({ currentTarget: { value: '925' } }));
  act(() => renderer.root.findByProps({ 'aria-label': 'Assertion 3 manual JSON path' }).props.onChange({ currentTarget: { value: '$.edited' } }));
  act(() => renderer.root.findByProps({ 'aria-label': 'Move assertion 3 up' }).props.onClick());
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 2 manual JSON path' }).props.value).toBe('$.edited');
  act(() => renderer.root.findByProps({ 'aria-label': 'Remove assertion 1' }).props.onClick());
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 manual JSON path' }).props.value).toBe('$.edited');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 2 expected value' }).props.value).toBe('925');

  act(() => button(renderer.root, 'Save configuration').props.onClick());
  await settle();
  const firstPayload = JSON.parse(request.mock.calls[0][1].body as string);
  expect(firstPayload.assertions).toEqual([
    { kind: 'json_path', operator: 'exists', path: '$.edited' },
    { kind: 'latency', operator: 'less_than_or_equal', expected: 925 },
  ]);
  expect(firstPayload.headers).toEqual([{ name: 'Authorization', action: 'preserve' }]);
  expect(text(renderer.root.findByProps({ 'data-testid': 'assertion-errors-1' }))).toContain('Latency must be within the accepted range.');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 manual JSON path' }).props.value).toBe('$.edited');

  act(() => button(renderer.root, 'Save configuration').props.onClick());
  await settle();
  expect(text(renderer.root.findByProps({ 'data-testid': 'save-error' }))).toContain('reload before saving');
  expect(button(renderer.root, 'Reload saved configuration')).toBeTruthy();
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 manual JSON path' }).props.value).toBe('$.edited');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 2 expected value' }).props.value).toBe('925');
  expect(reload).not.toHaveBeenCalled();

  act(() => button(renderer.root, 'Save configuration').props.onClick());
  await settle();
  expect(renderer.root.findAllByProps({ 'data-testid': 'save-error' })).toHaveLength(0);
  expect(text(renderer)).toContain('configuration version 5');
  expect(renderer.root.findByProps({ 'aria-label': 'Endpoint URL' }).props.value).toBe('https://normalized.example.test/v2');
  expect(renderer.root.findByProps({ 'aria-label': 'Request method' }).props.value).toBe('PATCH');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 1 manual JSON path' }).props.value).toBe('$.edited');
  expect(renderer.root.findByProps({ 'aria-label': 'Assertion 2 expected value' }).props.value).toBe('925');
  expect(text(renderer.root.findByProps({ 'data-testid': 'header-mask-0' }))).toBe('[REDACTED]');
  expect(renderer.root.findAllByProps({ 'aria-label': 'Header 1 new value' })).toHaveLength(0);
  expect(request).toHaveBeenCalledTimes(3);
}/* AC-web-dashboard-api-builder-15 */);
