import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const mobileRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'checkybot-widget-prebuild-'));

try {
  console.log('[widget-config] preparing isolated Expo prebuild fixture');
  fs.copyFileSync(path.join(mobileRoot, 'app.json'), path.join(tempRoot, 'app.json'));
  fs.copyFileSync(path.join(mobileRoot, 'package.json'), path.join(tempRoot, 'package.json'));
  fs.cpSync(path.join(mobileRoot, 'plugins'), path.join(tempRoot, 'plugins'), { recursive: true });
  fs.symlinkSync(path.join(mobileRoot, 'node_modules'), path.join(tempRoot, 'node_modules'), 'dir');

  for (const pass of [1, 2]) {
    console.log(`[widget-config] Expo iOS prebuild pass ${pass}/2`);
    const result = spawnSync(
      process.execPath,
      [path.join(mobileRoot, 'node_modules/expo/bin/cli'), 'prebuild', '--platform', 'ios', '--no-install'],
      { cwd: tempRoot, encoding: 'utf8', env: { ...process.env, CI: '1' } },
    );
    if (result.status !== 0) {
      process.stdout.write(result.stdout);
      process.stderr.write(result.stderr);
      throw new Error(`Expo prebuild failed with exit ${result.status}`);
    }
  }

  console.log('[widget-config] checking generated native target and sources');
  const project = fs.readdirSync(path.join(tempRoot, 'ios')).find((file) => file.endsWith('.xcodeproj'));
  assert(project, 'Expected generated Xcode project');
  const pbxproj = fs.readFileSync(path.join(tempRoot, 'ios', project, 'project.pbxproj'), 'utf8');
  assert.equal((pbxproj.match(/productType = "com\.apple\.product-type\.app-extension";/g) || []).length, 1);
  assert.match(pbxproj, /Begin PBXTargetDependency section/);
  assert.match(pbxproj, /CheckybotStatusWidget\.appex/);
  assert.match(pbxproj, /CheckybotStatusWidget\/CheckybotStatusWidget\.entitlements/);

  const widgetRoot = path.join(tempRoot, 'ios', 'CheckybotStatusWidget');
  const swift = fs.readFileSync(path.join(widgetRoot, 'StatusWidget.swift'), 'utf8');
  const info = fs.readFileSync(path.join(widgetRoot, 'CheckybotStatusWidget-Info.plist'), 'utf8');
  assert.match(info, /com\.apple\.widgetkit-extension/);
  assert.match(swift, /\.supportedFamilies\(\[\.systemMedium\]\)/);
  assert.match(swift, /\[\("servers", "Servers"\), \("websites", "Websites"\), \("apis", "APIs"\)\]/);
  assert.match(swift, /\["healthy", "warn", "down"\]/);
  assert.match(swift, /appending\(path: "api\/status-summary"\)/);
  assert(swift.includes('"Bearer \\(token)"'));

  const appRoot = fs.readdirSync(path.join(tempRoot, 'ios')).find((file) => {
    const candidate = path.join(tempRoot, 'ios', file, 'AppDelegate.swift');
    return fs.existsSync(candidate);
  });
  assert(appRoot, 'Expected generated application source group');
  const appDelegate = fs.readFileSync(path.join(tempRoot, 'ios', appRoot, 'AppDelegate.swift'), 'utf8');
  assert.equal((appDelegate.match(/StatusWidgetRefreshBridge\.shared\.handle/g) || []).length, 1);
  assert(fs.existsSync(path.join(tempRoot, 'ios', appRoot, 'StatusWidgetRefreshBridge.swift')));
  assert(!fs.existsSync(path.join(tempRoot, 'android')), 'The iOS widget plugin generated an Android module');
  console.log('[widget-config] PASS: one medium iOS extension, idempotent bridge, no Android widget module');
} finally {
  fs.rmSync(tempRoot, { recursive: true, force: true });
}
