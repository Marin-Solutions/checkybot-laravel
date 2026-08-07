export interface WidgetRefreshStateStore {
  hasProcessed(operationId: string): Promise<boolean>;
  record(operationId: string, receivedAtMs: number): Promise<void>;
}

export interface WidgetTimelineReloader {
  reloadAllTimelines(): Promise<void> | void;
}

export interface WidgetRefreshPayload {
  refreshWidget: true;
  operation_id: string;
  projectUuid?: string;
}

export type WidgetRefreshResult = 'reloaded' | 'duplicate' | 'ignored';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export class StatusWidgetRefreshBridge {
  constructor(
    private readonly state: WidgetRefreshStateStore,
    private readonly reloader: WidgetTimelineReloader,
    private readonly now: () => number = Date.now,
  ) {}

  async handle(payload: unknown): Promise<WidgetRefreshResult> {
    if (!isRefreshPayload(payload)) return 'ignored';
    if (await this.state.hasProcessed(payload.operation_id)) return 'duplicate';

    // Persist before asking iOS for a best-effort reload. If the process is suspended
    // immediately afterwards, the hint remains and a duplicate cannot cause a storm.
    await this.state.record(payload.operation_id, this.now());
    await this.reloader.reloadAllTimelines();
    return 'reloaded';
  }
}

export function isRefreshPayload(value: unknown): value is WidgetRefreshPayload {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return false;
  const payload = value as Record<string, unknown>;
  return payload.refreshWidget === true
    && typeof payload.operation_id === 'string'
    && UUID.test(payload.operation_id)
    && (payload.projectUuid === undefined || (typeof payload.projectUuid === 'string' && UUID.test(payload.projectUuid)));
}
