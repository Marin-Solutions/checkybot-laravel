import { CheckybotApiClient } from '../../../mobile/src/api/CheckybotApiClient';
import { DeviceLifecycle } from '../../../mobile/src/notifications/DeviceLifecycle';
import type { PushPermissionResult, PushTokenProvider } from '../../../mobile/src/notifications/NativePushProvider';
import type { CredentialKey, CredentialVault } from '../../../mobile/src/security/CredentialVault';

const projectUuid = '11111111-1111-4111-8111-111111111111';
const installationId = '22222222-2222-4222-8222-222222222222';
const deviceId = '33333333-3333-4333-8333-333333333333';
const authToken = 'private-mobile-user-credential';
const firstExpoToken = 'ExponentPushToken[private_first]';
const rotatedExpoToken = 'ExpoPushToken[private_rotated]';

class MockSecureVault implements CredentialVault {
  values = new Map<CredentialKey, string>([['mobileUserToken', authToken]]);
  async get(key: CredentialKey) { return this.values.get(key) ?? null; }
  async set(key: CredentialKey, value: string) { this.values.set(key, value); }
  async remove(key: CredentialKey) { this.values.delete(key); }
}

function response(status: number, body: unknown = null): Response {
  return { ok: status >= 200 && status < 300, status, json: async () => body } as Response;
}

function registered() {
  return {
    data: {
      id: deviceId,
      installation_id: installationId,
      platform: 'ios',
      project_uuid: projectUuid,
      active: true,
      registered_at: '2026-08-07T12:00:00Z',
    },
  };
}

function nativeSequence(...values: PushPermissionResult[]): PushTokenProvider {
  return { requestToken: jest.fn().mockImplementation(() => Promise.resolve(values.shift()!)) };
}

test.each(['granted', 'provisional'] as const)('%s permission registers through the declared POST route', async (permission) => {
  const vault = new MockSecureVault();
  const request = jest.fn().mockResolvedValue(response(201, registered()));
  const lifecycle = new DeviceLifecycle(
    new CheckybotApiClient(vault, 'https://checkybot.test', request),
    vault,
    nativeSequence({ permission, expoPushToken: firstExpoToken, platform: 'ios' }),
    () => installationId,
  );
  await lifecycle.synchronize(projectUuid, '1.2.3+42');
  expect(request).toHaveBeenCalledTimes(1);
  const [url, init] = request.mock.calls[0]!;
  expect(url).toBe('https://checkybot.test/api/v1/push-devices');
  expect(init.method).toBe('POST');
  expect(JSON.parse(init.body)).toEqual({
    installation_id: installationId, expo_push_token: firstExpoToken, platform: 'ios',
    project_uuid: projectUuid, permission, app_version: '1.2.3+42',
  });
  expect(vault.values.get('pushDeviceId')).toBe(deviceId);
});

test('token rotation reuses one stable installation', async () => {
  const vault = new MockSecureVault();
  const request = jest.fn()
    .mockResolvedValueOnce(response(201, registered()))
    .mockResolvedValueOnce(response(200, registered()));
  const native = nativeSequence(
    { permission: 'granted', expoPushToken: firstExpoToken, platform: 'ios' },
    { permission: 'provisional', expoPushToken: rotatedExpoToken, platform: 'ios' },
  );
  const lifecycle = new DeviceLifecycle(new CheckybotApiClient(vault, '', request), vault, native, () => installationId);
  await lifecycle.synchronize(projectUuid, '1.0.0');
  await lifecycle.synchronize(projectUuid, '1.0.1');
  const bodies = request.mock.calls.map((call) => JSON.parse(call[1].body));
  expect(bodies.map((body) => body.installation_id)).toEqual([installationId, installationId]);
  expect(bodies.map((body) => body.expo_push_token)).toEqual([firstExpoToken, rotatedExpoToken]);
});

test('denied permission sends no registration', async () => {
  const vault = new MockSecureVault();
  const request = jest.fn();
  const lifecycle = new DeviceLifecycle(
    new CheckybotApiClient(vault, '', request), vault,
    nativeSequence({ permission: 'denied', expoPushToken: null, platform: 'android' }),
  );
  await lifecycle.synchronize(projectUuid, '1.0.0');
  expect(request).not.toHaveBeenCalled();
  expect(vault.values.has('installationId')).toBe(false);
});

test('sign out deactivates the owned device and clears only secure-vault credentials', async () => {
  const vault = new MockSecureVault();
  vault.values.set('pushDeviceId', deviceId);
  vault.values.set('statusApiToken', 'private-status-token');
  const request = jest.fn().mockResolvedValue(response(204));
  const lifecycle = new DeviceLifecycle(new CheckybotApiClient(vault, '', request), vault, nativeSequence());
  await lifecycle.signOut();
  expect(request).toHaveBeenCalledWith(`/api/v1/push-devices/${deviceId}`, expect.objectContaining({ method: 'DELETE' }));
  expect(vault.values.has('mobileUserToken')).toBe(false);
  expect(vault.values.has('statusApiToken')).toBe(false);
  expect(vault.values.has('pushDeviceId')).toBe(false);
});

test('credentials and Expo tokens are never logged or returned by lifecycle state', async () => {
  const log = jest.spyOn(console, 'log').mockImplementation(() => undefined);
  const error = jest.spyOn(console, 'error').mockImplementation(() => undefined);
  const vault = new MockSecureVault();
  const request = jest.fn().mockResolvedValue(response(201, registered()));
  const lifecycle = new DeviceLifecycle(
    new CheckybotApiClient(vault, '', request), vault,
    nativeSequence({ permission: 'granted', expoPushToken: firstExpoToken, platform: 'ios' }),
    () => installationId,
  );
  expect(await lifecycle.synchronize(projectUuid, '1.0.0')).toBeUndefined();
  expect(log).not.toHaveBeenCalled();
  expect(error).not.toHaveBeenCalled();
  expect(JSON.stringify([...vault.values.entries()])).not.toContain(firstExpoToken);
  log.mockRestore();
  error.mockRestore();
});
