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

async function settle() {
  await act(async () => { await Promise.resolve(); await Promise.resolve(); });
}

it('keeps stored headers masked and submits preserve without any value by default', async () => {
  const storedPlaintext = 'Bearer plaintext-that-must-never-render';
  const request = jest.fn<ReturnType<BuilderRequest>, Parameters<BuilderRequest>>(() => response(200, { data: liveSample }));
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<ApiAssertionBuilder {...builderProps()} request={request} />); });

  expect(renderer.root.findByProps({ 'aria-label': 'Header 1 name' }).props.value).toBe('Authorization');
  expect(text(renderer.root.findByProps({ 'data-testid': 'header-mask-0' }))).toBe('[REDACTED]');
  expect(text(renderer)).not.toContain(storedPlaintext);
  expect(JSON.stringify(renderer.toJSON())).not.toContain(storedPlaintext);
  expect(renderer.root.findAllByProps({ 'aria-label': 'Header 1 new value' })).toHaveLength(0);

  act(() => button(renderer.root, 'Fetch live sample').props.onClick());
  await settle();
  const body = JSON.parse(request.mock.calls[0][1].body as string);
  expect(body.headers).toEqual([{ name: 'Authorization', action: 'preserve' }]);
  expect(JSON.stringify(body)).not.toContain(storedPlaintext);
}/* AC-web-dashboard-api-builder-13 */);

it('requires explicit replace before accepting a new secret and supports explicit removal', async () => {
  const replacement = 'Bearer intentionally-new-value';
  const request = jest.fn<ReturnType<BuilderRequest>, Parameters<BuilderRequest>>(() => response(200, { data: liveSample }));
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => { renderer = TestRenderer.create(<ApiAssertionBuilder {...builderProps()} request={request} />); });

  expect(renderer.root.findAllByProps({ 'aria-label': 'Header 1 new value' })).toHaveLength(0);
  act(() => button(renderer.root, 'Replace').props.onClick());
  const secretInput = renderer.root.findByProps({ 'aria-label': 'Header 1 new value' });
  expect(secretInput.props.type).toBe('password');
  act(() => secretInput.props.onChange({ currentTarget: { value: replacement } }));
  act(() => button(renderer.root, 'Fetch live sample').props.onClick());
  await settle();
  expect(JSON.parse(request.mock.calls[0][1].body as string).headers).toEqual([
    { name: 'Authorization', action: 'set', value: replacement },
  ]);

  const headerRow = renderer.root.find((node) => node.type === 'fieldset' && node.props['data-header-row'] === 0);
  act(() => button(headerRow, 'Remove').props.onClick());
  expect(renderer.root.findAllByProps({ 'aria-label': 'Header 1 new value' })).toHaveLength(0);
  act(() => button(renderer.root, 'Fetch live sample').props.onClick());
  await settle();
  expect(JSON.parse(request.mock.calls[1][1].body as string).headers).toEqual([
    { name: 'Authorization', action: 'remove' },
  ]);
  expect(text(renderer.root.findByProps({ 'data-testid': 'header-action-0' }))).toContain('remove');
}/* AC-web-dashboard-api-builder-13 */);
