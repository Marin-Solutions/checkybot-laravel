const fs = require('fs');
const path = require('path');
const {
  IOSConfig,
  createRunOncePlugin,
  withAppDelegate,
  withEntitlementsPlist,
  withXcodeProject,
} = require('@expo/config-plugins');

const TARGET_NAME = 'CheckybotStatusWidget';
const APP_GROUP = 'group.dev.checkybot.status';
const WIDGET_KIND = 'CheckybotStatusWidget';
const SOURCE_DIR = path.join(__dirname, 'status-widget');

const appDelegateMethod = `
  // Checkybot WidgetKit refresh accelerator. Scheduled direct API fetches remain authoritative.
  public override func application(
    _ application: UIApplication,
    didReceiveRemoteNotification userInfo: [AnyHashable: Any],
    fetchCompletionHandler completionHandler: @escaping (UIBackgroundFetchResult) -> Void
  ) {
    StatusWidgetRefreshBridge.shared.handle(userInfo: userInfo)
    super.application(application, didReceiveRemoteNotification: userInfo, fetchCompletionHandler: completionHandler)
  }
`;

function withStatusWidget(config, options = {}) {
  const bundleIdentifier = config.ios?.bundleIdentifier;
  if (!bundleIdentifier) throw new Error('withStatusWidget requires expo.ios.bundleIdentifier.');

  config.extra = {
    ...config.extra,
    statusWidget: {
      targetName: TARGET_NAME,
      kind: WIDGET_KIND,
      family: 'systemMedium',
      rows: ['servers', 'websites', 'apis'],
      columns: ['healthy', 'warn', 'down'],
      platform: 'ios',
    },
  };

  config = withEntitlementsPlist(config, (entitlementsConfig) => {
    entitlementsConfig.modResults['com.apple.security.application-groups'] = unique([
      ...(entitlementsConfig.modResults['com.apple.security.application-groups'] || []),
      APP_GROUP,
    ]);
    entitlementsConfig.modResults['keychain-access-groups'] = unique([
      ...(entitlementsConfig.modResults['keychain-access-groups'] || []),
      `$(AppIdentifierPrefix)${APP_GROUP}`,
    ]);
    return entitlementsConfig;
  });

  config = withAppDelegate(config, (appDelegateConfig) => {
    const marker = 'StatusWidgetRefreshBridge.shared.handle(userInfo: userInfo)';
    if (!appDelegateConfig.modResults.contents.includes(marker)) {
      const classBoundary = '\n}\n\nclass ReactNativeDelegate';
      if (!appDelegateConfig.modResults.contents.includes(classBoundary)) {
        throw new Error('Unable to install status widget notification bridge in AppDelegate.swift.');
      }
      appDelegateConfig.modResults.contents = appDelegateConfig.modResults.contents.replace(
        classBoundary,
        `${appDelegateMethod}\n}\n\nclass ReactNativeDelegate`,
      );
    }
    return appDelegateConfig;
  });

  config = withXcodeProject(config, (xcodeConfig) => {
    const project = xcodeConfig.modResults;
    const projectRoot = xcodeConfig.modRequest.projectRoot;
    const iosRoot = xcodeConfig.modRequest.platformProjectRoot;
    const projectName = IOSConfig.XcodeUtils.getProjectName(projectRoot);
    const widgetRoot = path.join(iosRoot, TARGET_NAME);
    const appRoot = path.join(iosRoot, projectName);
    fs.mkdirSync(widgetRoot, { recursive: true });
    fs.mkdirSync(appRoot, { recursive: true });
    fs.copyFileSync(path.join(SOURCE_DIR, 'StatusWidget.swift'), path.join(widgetRoot, 'StatusWidget.swift'));
    fs.copyFileSync(path.join(SOURCE_DIR, 'StatusWidgetRefreshBridge.swift'), path.join(appRoot, 'StatusWidgetRefreshBridge.swift'));
    fs.writeFileSync(path.join(widgetRoot, `${TARGET_NAME}-Info.plist`), widgetInfoPlist());
    fs.writeFileSync(path.join(widgetRoot, `${TARGET_NAME}.entitlements`), widgetEntitlementsPlist());

    const appTarget = IOSConfig.XcodeUtils.getApplicationNativeTarget({ project, projectName });
    IOSConfig.XcodeUtils.addBuildSourceFileToGroup({
      filepath: `${projectName}/StatusWidgetRefreshBridge.swift`,
      groupName: projectName,
      project,
      targetUuid: appTarget.uuid,
    });

    let widgetTarget = findTarget(project, TARGET_NAME);
    if (!widgetTarget) {
      // node-xcode only creates the app -> extension dependency when these sections exist.
      project.hash.project.objects.PBXTargetDependency ||= {};
      project.hash.project.objects.PBXContainerItemProxy ||= {};
      widgetTarget = project.addTarget(
        TARGET_NAME,
        'app_extension',
        TARGET_NAME,
        `${bundleIdentifier}.widget`,
      );
      const group = project.addPbxGroup(
        ['StatusWidget.swift', `${TARGET_NAME}-Info.plist`, `${TARGET_NAME}.entitlements`],
        TARGET_NAME,
        TARGET_NAME,
      );
      const rootGroup = project.getPBXGroupByKey(project.getFirstProject().firstProject.mainGroup);
      rootGroup.children.push({ value: group.uuid, comment: TARGET_NAME });
      project.addBuildPhase(['StatusWidget.swift'], 'PBXSourcesBuildPhase', 'Sources', widgetTarget.uuid);
      project.addBuildPhase([], 'PBXFrameworksBuildPhase', 'Frameworks', widgetTarget.uuid);
      project.addBuildPhase([], 'PBXResourcesBuildPhase', 'Resources', widgetTarget.uuid);
    }

    for (const [, buildConfig] of IOSConfig.XcodeUtils.getBuildConfigurationsForListId(
      project,
      widgetTarget.pbxNativeTarget.buildConfigurationList,
    )) {
      Object.assign(buildConfig.buildSettings, {
        APPLICATION_EXTENSION_API_ONLY: 'YES',
        CODE_SIGN_ENTITLEMENTS: `"${TARGET_NAME}/${TARGET_NAME}.entitlements"`,
        CURRENT_PROJECT_VERSION: '1',
        GENERATE_INFOPLIST_FILE: 'NO',
        INFOPLIST_FILE: `"${TARGET_NAME}/${TARGET_NAME}-Info.plist"`,
        IPHONEOS_DEPLOYMENT_TARGET: options.deploymentTarget || '17.0',
        MARKETING_VERSION: config.version || '1.0.0',
        PRODUCT_BUNDLE_IDENTIFIER: `"${bundleIdentifier}.widget"`,
        SKIP_INSTALL: 'YES',
        SWIFT_VERSION: '5.0',
        TARGETED_DEVICE_FAMILY: '1',
      });
    }

    return xcodeConfig;
  });

  return config;
}

function findTarget(project, name) {
  for (const [uuid, target] of Object.entries(project.pbxNativeTargetSection())) {
    if (uuid.endsWith('_comment')) continue;
    if (String(target.name).replaceAll('"', '') === name) return { uuid, pbxNativeTarget: target };
  }
  return null;
}

function unique(values) {
  return [...new Set(values)];
}

function widgetInfoPlist() {
  return `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>CFBundleDisplayName</key><string>Checkybot Status</string>
  <key>CFBundleExecutable</key><string>$(EXECUTABLE_NAME)</string>
  <key>CFBundleIdentifier</key><string>$(PRODUCT_BUNDLE_IDENTIFIER)</string>
  <key>CFBundleInfoDictionaryVersion</key><string>6.0</string>
  <key>CFBundleName</key><string>$(PRODUCT_NAME)</string>
  <key>CFBundlePackageType</key><string>$(PRODUCT_BUNDLE_PACKAGE_TYPE)</string>
  <key>CFBundleShortVersionString</key><string>$(MARKETING_VERSION)</string>
  <key>CFBundleVersion</key><string>$(CURRENT_PROJECT_VERSION)</string>
  <key>NSExtension</key><dict>
    <key>NSExtensionPointIdentifier</key><string>com.apple.widgetkit-extension</string>
  </dict>
</dict></plist>
`;
}

function widgetEntitlementsPlist() {
  return `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>com.apple.security.application-groups</key><array><string>${APP_GROUP}</string></array>
  <key>keychain-access-groups</key><array><string>$(AppIdentifierPrefix)${APP_GROUP}</string></array>
</dict></plist>
`;
}

module.exports = createRunOncePlugin(withStatusWidget, 'checkybot-status-widget', '1.0.0');
module.exports.withStatusWidget = withStatusWidget;
module.exports.constants = { APP_GROUP, TARGET_NAME, WIDGET_KIND };
