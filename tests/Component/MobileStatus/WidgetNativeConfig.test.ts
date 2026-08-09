import fs from 'node:fs';
import path from 'node:path';

const mobileRoot = path.resolve(__dirname, '../../../mobile');
const appConfig = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'app.json'), 'utf8'));
const widgetSource = fs.readFileSync(path.join(mobileRoot, 'plugins/status-widget/StatusWidget.swift'), 'utf8');
const bridgeSource = fs.readFileSync(path.join(mobileRoot, 'plugins/status-widget/StatusWidgetRefreshBridge.swift'), 'utf8');
// eslint-disable-next-line @typescript-eslint/no-require-imports
const widgetPlugin = require('../../../mobile/plugins/withStatusWidget');

test('Expo configuration declares one iOS-only WidgetKit plugin and no Android widget module', () => {
  const pluginEntries = appConfig.expo.plugins.filter((entry: unknown) => Array.isArray(entry) && entry[0] === './plugins/withStatusWidget');
  expect(pluginEntries).toHaveLength(1);
  expect(widgetPlugin.constants).toEqual({
    APP_GROUP: 'group.dev.checkybot.status',
    TARGET_NAME: 'CheckybotStatusWidget',
    WIDGET_KIND: 'CheckybotStatusWidget',
  });
  expect(fs.existsSync(path.join(mobileRoot, 'plugins/android'))).toBe(false);
  expect(fs.existsSync(path.join(mobileRoot, 'android/widget'))).toBe(false);
});

test('native medium widget layout contract snapshot fixes row and column order', () => {
  expect(widgetSource).toContain('private let rows: [(key: String, label: String)] = [("servers", "Servers"), ("websites", "Websites"), ("apis", "APIs")]');
  expect(widgetSource).toContain('private let columns = ["healthy", "warn", "down"]');
  expect(widgetSource).toContain('.supportedFamilies([.systemMedium])');
  expect({
    family: 'systemMedium',
    rows: ['Servers', 'Websites', 'APIs'],
    columns: ['healthy', 'warn', 'down'],
    cellOrder: 'row-major',
  }).toMatchInlineSnapshot(`
{
  "cellOrder": "row-major",
  "columns": [
    "healthy",
    "warn",
    "down",
  ],
  "family": "systemMedium",
  "rows": [
    "Servers",
    "Websites",
    "APIs",
  ],
}
`);
});

test('native timeline and refresh sources retain direct-fetch and idempotent bridge invariants', () => {
  expect(widgetSource).toContain('appending(path: "api/status-summary")');
  expect(widgetSource).toContain('request.setValue("Bearer \\(token)", forHTTPHeaderField: "Authorization")');
  expect(widgetSource).not.toMatch(/refreshWidget.*counts/s);
  expect(bridgeSource).toContain('WidgetCenter.shared.reloadTimelines(ofKind: statusWidgetKind)');
  expect(bridgeSource).toContain('guard !processed.contains(rawOperationId) else { return false }');
  expect(bridgeSource).toContain('defaults?.set(now(), forKey: refreshHintKey)');
});
