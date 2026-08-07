import Security
import SwiftUI
import WidgetKit

private let appGroup = "group.dev.checkybot.status"
private let widgetKind = "CheckybotStatusWidget"
private let staleAfter: TimeInterval = 900
private let rows: [(key: String, label: String)] = [("servers", "Servers"), ("websites", "Websites"), ("apis", "APIs")]
private let columns = ["healthy", "warn", "down"]

enum StatusWidgetPhase: String, Codable {
  case loading, healthy, problem, stale, auth, offline
}

struct StatusCounts: Codable, Equatable {
  let healthy: Int
  let warn: Int
  let down: Int

  subscript(column: String) -> Int {
    switch column {
    case "healthy": healthy
    case "warn": warn
    default: down
    }
  }
}

struct StatusSummary: Codable, Equatable {
  let counts: [String: StatusCounts]
  let updatedAt: Date?
  let stale: Bool

  enum CodingKeys: String, CodingKey {
    case counts, stale
    case updatedAt = "updated_at"
  }

  var hasProblems: Bool {
    rows.contains { row in
      guard let count = counts[row.key] else { return false }
      return count.warn > 0 || count.down > 0
    }
  }

  var hasAllNineCounts: Bool {
    Set(counts.keys) == Set(rows.map(\.key)) && counts.values.allSatisfy {
      $0.healthy >= 0 && $0.warn >= 0 && $0.down >= 0
    }
  }
}

private struct StatusSummaryEnvelope: Codable {
  let data: StatusSummary
}

struct StatusWidgetEntry: TimelineEntry {
  let date: Date
  let phase: StatusWidgetPhase
  let summary: StatusSummary?
  let visibleUpdatedAt: Date
  let message: String
}

protocol StatusSummaryHTTPClient {
  func getStatusSummary(token: String, completion: @escaping (Result<StatusSummary, StatusFetchError>) -> Void)
}

enum StatusFetchError: Error {
  case auth
  case offline
}

final class DirectStatusSummaryHTTPClient: StatusSummaryHTTPClient {
  private let session: URLSession
  private let baseURL: URL

  init(session: URLSession = .shared, baseURL: URL = AppGroupSettings.apiBaseURL) {
    self.session = session
    self.baseURL = baseURL
  }

  func getStatusSummary(token: String, completion: @escaping (Result<StatusSummary, StatusFetchError>) -> Void) {
    let url = baseURL.appending(path: "api/status-summary")
    var request = URLRequest(url: url)
    request.httpMethod = "GET"
    request.setValue("application/json", forHTTPHeaderField: "Accept")
    request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")

    session.dataTask(with: request) { data, response, error in
      guard error == nil, let http = response as? HTTPURLResponse, let data else {
        completion(.failure(.offline))
        return
      }
      if http.statusCode == 401 || http.statusCode == 403 {
        completion(.failure(.auth))
        return
      }
      guard (200..<300).contains(http.statusCode),
            let envelope = try? Self.decoder.decode(StatusSummaryEnvelope.self, from: data),
            envelope.data.hasAllNineCounts else {
        completion(.failure(.offline))
        return
      }
      completion(.success(envelope.data))
    }.resume()
  }

  private static let decoder: JSONDecoder = {
    let decoder = JSONDecoder()
    decoder.dateDecodingStrategy = .iso8601
    return decoder
  }()
}

enum SharedStatusTokenStore {
  static func read() -> String? {
    let key = Data("statusApiToken".utf8)
    let query: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: "app",
      kSecAttrAccount as String: key,
      kSecAttrGeneric as String: key,
      kSecAttrAccessGroup as String: appGroup,
      kSecMatchLimit as String: kSecMatchLimitOne,
      kSecReturnData as String: true,
    ]
    var result: CFTypeRef?
    guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
          let data = result as? Data else { return nil }
    return String(data: data, encoding: .utf8)
  }
}

enum AppGroupSettings {
  private static let defaults = UserDefaults(suiteName: appGroup)

  static var apiBaseURL: URL {
    if let value = defaults?.string(forKey: "statusApiBaseURL"), let url = URL(string: value) { return url }
    return URL(string: "https://api.checkybot.dev/")!
  }

  static var problemsURL: URL {
    var components = URLComponents()
    components.scheme = "checkybot"
    components.host = "problems"
    var query = [URLQueryItem(name: "states", value: "warn,down")]
    if let project = defaults?.string(forKey: "activeProjectUuid") {
      query.append(URLQueryItem(name: "projectUuid", value: project))
    }
    components.queryItems = query
    return components.url!
  }
}

struct StatusTimelineProvider: TimelineProvider {
  private let client: StatusSummaryHTTPClient
  private let token: () -> String?
  private let now: () -> Date
  private let cache: StatusWidgetCache

  init(
    client: StatusSummaryHTTPClient = DirectStatusSummaryHTTPClient(),
    token: @escaping () -> String? = SharedStatusTokenStore.read,
    now: @escaping () -> Date = Date.init,
    cache: StatusWidgetCache = .shared
  ) {
    self.client = client
    self.token = token
    self.now = now
    self.cache = cache
  }

  func placeholder(in context: Context) -> StatusWidgetEntry {
    fallback(.loading, message: "Loading status")
  }

  func getSnapshot(in context: Context, completion: @escaping (StatusWidgetEntry) -> Void) {
    completion(fallback(.loading, message: "Loading status"))
  }

  func getTimeline(in context: Context, completion: @escaping (Timeline<StatusWidgetEntry>) -> Void) {
    let fetchedAt = now()
    guard let token = token() else {
      completion(timeline(fallback(.auth, message: "Authentication required", at: fetchedAt)))
      return
    }

    // A system reload always performs this authenticated request. Refresh hints only ask
    // WidgetCenter to schedule this path; they never contain replacement status data.
    client.getStatusSummary(token: token) { result in
      switch result {
      case .success(let summary):
        cache.store(summary)
        let isLocallyStale = summary.updatedAt.map { fetchedAt.timeIntervalSince($0) > staleAfter } ?? true
        let phase: StatusWidgetPhase = summary.stale || isLocallyStale ? .stale : summary.hasProblems ? .problem : .healthy
        completion(timeline(StatusWidgetEntry(
          date: fetchedAt,
          phase: phase,
          summary: summary,
          visibleUpdatedAt: summary.updatedAt ?? fetchedAt,
          message: phase == .stale ? "Stale data" : phase == .problem ? "Problems detected" : "All systems healthy"
        )))
      case .failure(.auth):
        completion(timeline(fallback(.auth, message: "Authentication required", at: fetchedAt)))
      case .failure(.offline):
        completion(timeline(fallback(.offline, message: "Status unavailable", at: fetchedAt)))
      }
    }
  }

  private func fallback(_ phase: StatusWidgetPhase, message: String, at date: Date? = nil) -> StatusWidgetEntry {
    let createdAt = date ?? now()
    let summary = cache.load()
    return StatusWidgetEntry(
      date: createdAt,
      phase: phase,
      summary: summary,
      visibleUpdatedAt: summary?.updatedAt ?? createdAt,
      message: message
    )
  }

  private func timeline(_ entry: StatusWidgetEntry) -> Timeline<StatusWidgetEntry> {
    Timeline(entries: [entry], policy: .after(entry.date.addingTimeInterval(300)))
  }
}

final class StatusWidgetCache {
  static let shared = StatusWidgetCache()
  private let defaults = UserDefaults(suiteName: appGroup)
  private let key = "lastStatusSummary"

  func store(_ summary: StatusSummary) {
    let encoder = JSONEncoder()
    encoder.dateEncodingStrategy = .iso8601
    defaults?.set(try? encoder.encode(summary), forKey: key)
  }

  func load() -> StatusSummary? {
    guard let data = defaults?.data(forKey: key) else { return nil }
    let decoder = JSONDecoder()
    decoder.dateDecodingStrategy = .iso8601
    return try? decoder.decode(StatusSummary.self, from: data)
  }
}

struct StatusWidgetView: View {
  let entry: StatusWidgetEntry

  var body: some View {
    VStack(alignment: .leading, spacing: 5) {
      HStack {
        Text(entry.message).font(.caption.bold()).foregroundStyle(statusTint)
        Spacer()
        Text(updatedLabel).font(.caption2).foregroundStyle(ageTint)
      }
      HStack(spacing: 4) {
        Text("").frame(maxWidth: .infinity)
        ForEach(columns, id: \.self) { column in
          Text(column.capitalized).font(.caption2).frame(maxWidth: .infinity)
        }
      }
      ForEach(rows, id: \.key) { row in
        HStack(spacing: 4) {
          Text(row.label).font(.caption2.bold()).frame(maxWidth: .infinity, alignment: .leading)
          ForEach(columns, id: \.self) { column in
            Text("\(entry.summary?.counts[row.key]?[column] ?? 0)")
              .font(.caption.monospacedDigit().bold())
              .frame(maxWidth: .infinity)
              .padding(.vertical, 2)
              .background(cellColor(row: row.key, column: column), in: RoundedRectangle(cornerRadius: 4))
          }
        }
      }
    }
    .padding(12)
    .opacity(entry.phase == .stale ? 0.68 : 1)
    .containerBackground(.background, for: .widget)
    .widgetURL(AppGroupSettings.problemsURL)
  }

  private var updatedLabel: String {
    let minutes = max(0, Int(entry.date.timeIntervalSince(entry.visibleUpdatedAt) / 60))
    return "Updated \(minutes)m ago"
  }

  private var statusTint: Color {
    switch entry.phase {
    case .healthy: .green
    case .problem: .red
    case .stale: .orange
    case .loading, .auth, .offline: .secondary
    }
  }

  private var ageTint: Color { entry.phase == .stale ? .orange : .secondary }

  private func cellColor(row: String, column: String) -> Color {
    let count = entry.summary?.counts[row]?[column] ?? 0
    if entry.phase == .stale { return .orange.opacity(0.16) }
    if entry.phase == .problem && column == "down" && count > 0 { return .red.opacity(0.28) }
    if entry.phase == .problem && column == "warn" && count > 0 { return .yellow.opacity(0.32) }
    if entry.phase == .healthy && column == "healthy" { return .green.opacity(0.22) }
    return .secondary.opacity(0.10)
  }
}

struct CheckybotStatusWidget: Widget {
  var body: some WidgetConfiguration {
    StaticConfiguration(kind: widgetKind, provider: StatusTimelineProvider()) { entry in
      StatusWidgetView(entry: entry)
    }
    .configurationDisplayName("Checkybot status")
    .description("Servers, websites, and APIs at a glance.")
    .supportedFamilies([.systemMedium])
  }
}

@main
struct CheckybotStatusWidgetBundle: WidgetBundle {
  var body: some Widget { CheckybotStatusWidget() }
}
