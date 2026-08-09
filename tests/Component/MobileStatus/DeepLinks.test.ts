import { loadIosWidgetBridge } from '../../../mobile/src/native/widgetModule';
import { notificationProblemsRoute, widgetProblemsRoute } from '../../../mobile/src/routing/deepLinks';

const projectUuid = '11111111-1111-4111-8111-111111111111';
const foreignProjectUuid = '22222222-2222-4222-8222-222222222222';
const groupUuid = '33333333-3333-4333-8333-333333333333';
const monitorOne = '44444444-4444-4444-8444-444444444444';
const monitorTwo = '55555555-5555-4555-8555-555555555555';

const notification = (phase: 'incident' | 'recovery') => ({
  route: 'problems', projectUuid, groupId: groupUuid,
  monitorUuids: [monitorOne, monitorTwo], phase, severity: 'critical', refreshWidget: true,
});

test.each(['incident', 'recovery'] as const)('%s notifications preserve exact project, group and unique monitor filters', (phase) => {
  const route = notificationProblemsRoute(notification(phase), projectUuid);
  expect(route).toEqual({
    pathname: '/problems',
    params: { projectUuid, groupUuid, monitorUuids: `${monitorOne},${monitorTwo}` },
    filter: { types: ['server', 'website', 'api'], states: ['warn', 'down'], severities: ['critical'] },
  });
});

test('widget links open the same route with warn and down filters', () => {
  expect(widgetProblemsRoute(`checkybot://problems?projectUuid=${projectUuid}&states=warn,down`, projectUuid)).toEqual({
    pathname: '/problems',
    params: { projectUuid, states: 'warn,down' },
    filter: { types: ['server', 'website', 'api'], states: ['warn', 'down'], severities: ['warn', 'critical'] },
  });
});

test('malformed, duplicate-monitor and foreign-project links are rejected', () => {
  expect(notificationProblemsRoute({ ...notification('incident'), projectUuid: foreignProjectUuid }, projectUuid)).toBeNull();
  expect(notificationProblemsRoute({ ...notification('incident'), monitorUuids: [monitorOne, monitorOne] }, projectUuid)).toBeNull();
  expect(notificationProblemsRoute({ ...notification('incident'), groupId: 'bad' }, projectUuid)).toBeNull();
  expect(widgetProblemsRoute(`checkybot://problems?projectUuid=${foreignProjectUuid}&states=warn,down`, projectUuid)).toBeNull();
  expect(widgetProblemsRoute(`https://example.test/problems?projectUuid=${projectUuid}&states=warn,down`, projectUuid)).toBeNull();
});

test('Android and web never attempt to load the iOS widget module', async () => {
  const loader = jest.fn();
  await expect(loadIosWidgetBridge('android', loader)).resolves.toBeNull();
  await expect(loadIosWidgetBridge('web', loader)).resolves.toBeNull();
  expect(loader).not.toHaveBeenCalled();
});
