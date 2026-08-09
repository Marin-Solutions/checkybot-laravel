import Foundation
import WidgetKit

private let statusWidgetAppGroup = "group.dev.checkybot.status"
private let statusWidgetKind = "CheckybotStatusWidget"

protocol WidgetTimelineReloading {
  func reloadStatusTimelines()
}

struct SystemWidgetTimelineReloader: WidgetTimelineReloading {
  func reloadStatusTimelines() {
    WidgetCenter.shared.reloadTimelines(ofKind: statusWidgetKind)
  }
}

final class StatusWidgetRefreshBridge {
  static let shared = StatusWidgetRefreshBridge()

  private let defaults: UserDefaults?
  private let reloader: WidgetTimelineReloading
  private let now: () -> Date
  private let lock = NSLock()
  private let operationIdsKey = "processedWidgetRefreshOperationIds"
  private let refreshHintKey = "lastWidgetRefreshHint"

  init(
    defaults: UserDefaults? = UserDefaults(suiteName: statusWidgetAppGroup),
    reloader: WidgetTimelineReloading = SystemWidgetTimelineReloader(),
    now: @escaping () -> Date = Date.init
  ) {
    self.defaults = defaults
    self.reloader = reloader
    self.now = now
  }

  @discardableResult
  func handle(userInfo: [AnyHashable: Any]) -> Bool {
    guard userInfo["refreshWidget"] as? Bool == true,
          let rawOperationId = userInfo["operation_id"] as? String,
          UUID(uuidString: rawOperationId) != nil else { return false }

    lock.lock()
    defer { lock.unlock() }

    var processed = defaults?.stringArray(forKey: operationIdsKey) ?? []
    guard !processed.contains(rawOperationId) else { return false }

    // Record the hint before the best-effort reload request. iOS may throttle or
    // suspend background work, but this never changes cached age/status data.
    defaults?.set(now(), forKey: refreshHintKey)
    if let projectUuid = userInfo["projectUuid"] as? String, UUID(uuidString: projectUuid) != nil {
      defaults?.set(projectUuid, forKey: "activeProjectUuid")
    }
    processed.append(rawOperationId)
    defaults?.set(Array(processed.suffix(128)), forKey: operationIdsKey)
    reloader.reloadStatusTimelines()
    return true
  }
}
