import {
  STATUS_KINDS,
  STATUS_STATES,
  deriveFreshness,
  parseStatusSummaryResponse,
  type StatusKind,
  type StatusState,
  type StatusSummary,
} from '../contracts/monitor-domain.generated';

export const WIDGET_ROWS = STATUS_KINDS;
export const WIDGET_COLUMNS = STATUS_STATES;
export const WIDGET_STALE_AFTER_SECONDS = 900;
export const WIDGET_PROBLEMS_STATES = 'warn,down' as const;

export type WidgetPhase = 'loading' | 'healthy' | 'problem' | 'stale' | 'auth' | 'offline';
export type WidgetTone = 'healthy-green' | 'problem' | 'warning-dimmed' | 'neutral';

export interface WidgetEntry {
  phase: WidgetPhase;
  summary: StatusSummary | null;
  sourceUpdatedAt: string | null;
  visibleUpdatedAtMs: number;
  createdAtMs: number;
  nextReloadAtMs: number;
  message: string;
}

export interface WidgetCell {
  row: StatusKind;
  column: StatusState;
  count: number;
  affected: boolean;
}

export interface WidgetViewModel {
  family: 'systemMedium';
  rowLabels: readonly ['Servers', 'Websites', 'APIs'];
  columnLabels: readonly ['healthy', 'warn', 'down'];
  cells: WidgetCell[];
  phase: WidgetPhase;
  tone: WidgetTone;
  statusLabel: string;
  updatedLabel: string;
  dimmed: boolean;
  deepLink: string;
}

export interface WidgetTokenStore {
  getStatusApiToken(): Promise<string | null>;
}

export interface WidgetResponse {
  status: number;
  json(): Promise<unknown>;
}

export type WidgetFetch = (url: string, init: { method: 'GET'; headers: Record<string, string> }) => Promise<WidgetResponse>;

const EMPTY_COUNTS: StatusSummary['counts'] = {
  servers: { healthy: 0, warn: 0, down: 0 },
  websites: { healthy: 0, warn: 0, down: 0 },
  apis: { healthy: 0, warn: 0, down: 0 },
};

export class StatusWidgetTimelineProvider {
  private lastSuccessfulSummary: StatusSummary | null = null;
  private readonly summaryUrl: string;

  constructor(
    apiBaseUrl: string,
    private readonly tokenStore: WidgetTokenStore,
    private readonly request: WidgetFetch,
    private readonly now: () => number = Date.now,
    private readonly reloadIntervalMs = 5 * 60 * 1000,
  ) {
    this.summaryUrl = new URL('/api/status-summary', apiBaseUrl).toString();
  }

  loadingEntry(): WidgetEntry {
    return this.fallbackEntry('loading', 'Loading status');
  }

  /** System timeline entry point. Push hints never provide status data to this method. */
  async scheduledReload(): Promise<WidgetEntry> {
    const token = await this.tokenStore.getStatusApiToken();
    if (!token) return this.fallbackEntry('auth', 'Authentication required');

    try {
      const response = await this.request(this.summaryUrl, {
        method: 'GET',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (response.status === 401 || response.status === 403) {
        return this.fallbackEntry('auth', 'Authentication required');
      }
      if (response.status < 200 || response.status >= 300) {
        return this.fallbackEntry('offline', 'Status unavailable');
      }

      const summary = parseStatusSummaryResponse(await response.json()).data;
      this.lastSuccessfulSummary = summary;
      return this.summaryEntry(summary);
    } catch {
      return this.fallbackEntry('offline', 'Status unavailable');
    }
  }

  private summaryEntry(summary: StatusSummary): WidgetEntry {
    const createdAtMs = this.now();
    const hasProblems = WIDGET_ROWS.some((row) => summary.counts[row].warn > 0 || summary.counts[row].down > 0);
    const stale = deriveFreshness(summary, createdAtMs) === 'stale';
    return {
      phase: stale ? 'stale' : hasProblems ? 'problem' : 'healthy',
      summary,
      sourceUpdatedAt: summary.updated_at,
      visibleUpdatedAtMs: validTimestamp(summary.updated_at) ?? createdAtMs,
      createdAtMs,
      nextReloadAtMs: createdAtMs + this.reloadIntervalMs,
      message: stale ? 'Stale data' : hasProblems ? 'Problems detected' : 'All systems healthy',
    };
  }

  private fallbackEntry(phase: 'loading' | 'auth' | 'offline', message: string): WidgetEntry {
    const createdAtMs = this.now();
    const cached = this.lastSuccessfulSummary;
    return {
      phase,
      summary: cached,
      sourceUpdatedAt: cached?.updated_at ?? null,
      visibleUpdatedAtMs: validTimestamp(cached?.updated_at ?? null) ?? createdAtMs,
      createdAtMs,
      nextReloadAtMs: createdAtMs + this.reloadIntervalMs,
      message,
    };
  }
}

export function renderStatusWidget(entry: WidgetEntry, projectUuid?: string, nowMs = entry.createdAtMs): WidgetViewModel {
  const summary = entry.summary ?? { counts: EMPTY_COUNTS, stale: true, updated_at: null };
  const cells = WIDGET_ROWS.flatMap((row) => WIDGET_COLUMNS.map((column) => ({
    row,
    column,
    count: summary.counts[row][column],
    affected: column !== 'healthy' && summary.counts[row][column] > 0,
  })));
  const tone: WidgetTone = entry.phase === 'healthy'
    ? 'healthy-green'
    : entry.phase === 'problem'
      ? 'problem'
      : entry.phase === 'stale'
        ? 'warning-dimmed'
        : 'neutral';
  const labels = new URLSearchParams({ states: WIDGET_PROBLEMS_STATES });
  if (projectUuid) labels.set('projectUuid', projectUuid);

  return {
    family: 'systemMedium',
    rowLabels: ['Servers', 'Websites', 'APIs'],
    columnLabels: WIDGET_COLUMNS,
    cells,
    phase: entry.phase,
    tone,
    statusLabel: entry.message,
    updatedLabel: `Updated ${Math.max(0, Math.floor((nowMs - entry.visibleUpdatedAtMs) / 60000))}m ago`,
    dimmed: entry.phase === 'stale',
    deepLink: `checkybot://problems?${labels.toString().replace('%2C', ',')}`,
  };
}

function validTimestamp(value: string | null): number | null {
  if (value === null) return null;
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? parsed : null;
}
