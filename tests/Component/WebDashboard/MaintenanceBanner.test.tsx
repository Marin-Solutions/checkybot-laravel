import React from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import Overview from '../../../resources/js/Pages/CheckybotDashboard/Overview';
import { overview } from './fixtures';

function render(scope: 'global' | 'project' | null, silenced = true) {
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(
      <Overview
        {...overview({
          maintenance: {
            silenced,
            effective_scope: scope,
            ends_at: scope ? '2026-08-07T12:30:00Z' : null,
            reason: scope ? 'Deploy in progress' : null,
          },
        })}
        formatDate={() => 'Aug 7, 2026, 12:30 PM'}
      />,
    );
  });
  return renderer;
}

describe.each(['global', 'project'] as const)('%s maintenance', (scope) => {
  it('renders a persistent, localized and keyboard-readable responsive header banner', () => {
    const renderer = render(scope);
    const banner = renderer.root.findByProps({ 'data-testid': 'maintenance-banner' });
    const content = banner.findAllByType('span').map((node) => node.children.join('')).join(' ');
    expect(banner.props.role).toBe('status');
    expect(banner.props.tabIndex).toBe(0);
    expect(banner.props['aria-label']).toBe(`${scope[0].toUpperCase()}${scope.slice(1)} maintenance window`);
    expect(content).toContain('Notifications silenced until Aug 7, 2026, 12:30 PM.');
    expect(banner.props.className).toContain('sticky top-0');
    expect(banner.props.className).toContain('sm:flex-row');
    expect(banner.props.className).toContain('focus-visible:ring-2');
  });
});

it('does not render a banner for inactive maintenance props', () => {
  expect(render(null, false).root.findAllByProps({ 'data-testid': 'maintenance-banner' })).toHaveLength(0);
  expect(render('project', false).root.findAllByProps({ 'data-testid': 'maintenance-banner' })).toHaveLength(0);
});
