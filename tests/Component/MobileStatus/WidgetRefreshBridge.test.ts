import {
  StatusWidgetRefreshBridge,
  type WidgetRefreshStateStore,
} from '../../../mobile/src/widget/StatusWidgetRefreshBridge';

const operationId = '11111111-1111-4111-8111-111111111111';
const projectUuid = '22222222-2222-4222-8222-222222222222';

class MemoryRefreshStore implements WidgetRefreshStateStore {
  readonly operations = new Map<string, number>();

  async hasProcessed(id: string): Promise<boolean> {
    return this.operations.has(id);
  }

  async record(id: string, at: number): Promise<void> {
    this.operations.set(id, at);
  }
}

test('a valid delivered refresh records a shared hint and reloads WidgetCenter once per operation', async () => {
  const store = new MemoryRefreshStore();
  const reloadAllTimelines = jest.fn();
  const bridge = new StatusWidgetRefreshBridge(store, { reloadAllTimelines }, () => 1_786_104_000_000);
  const payload = { refreshWidget: true, operation_id: operationId, projectUuid };

  await expect(bridge.handle(payload)).resolves.toBe('reloaded');
  await expect(bridge.handle(payload)).resolves.toBe('duplicate');

  expect(store.operations).toEqual(new Map([[operationId, 1_786_104_000_000]]));
  expect(reloadAllTimelines).toHaveBeenCalledTimes(1);
});

test.each([
  null,
  {},
  { refreshWidget: false, operation_id: operationId },
  { refreshWidget: 'true', operation_id: operationId },
  { refreshWidget: true },
  { refreshWidget: true, operation_id: 'not-a-uuid' },
  { refreshWidget: true, operation_id: operationId, projectUuid: 'foreign-garbage' },
])('malformed payload %p is ignored without recording or reloading', async (payload) => {
  const store = new MemoryRefreshStore();
  const reloadAllTimelines = jest.fn();
  const bridge = new StatusWidgetRefreshBridge(store, { reloadAllTimelines });
  await expect(bridge.handle(payload)).resolves.toBe('ignored');
  expect(store.operations.size).toBe(0);
  expect(reloadAllTimelines).not.toHaveBeenCalled();
});
