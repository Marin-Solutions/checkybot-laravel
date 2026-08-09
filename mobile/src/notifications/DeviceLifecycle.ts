import type { CheckybotClient } from '../api/CheckybotApiClient';
import type { PushTokenProvider } from './NativePushProvider';
import type { CredentialVault } from '../security/CredentialVault';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export class DeviceLifecycle {
  constructor(
    private readonly api: CheckybotClient,
    private readonly vault: CredentialVault,
    private readonly nativePush: PushTokenProvider,
    private readonly createInstallationId: () => string = createUuid,
  ) {}

  async synchronize(projectUuid: string, appVersion: string): Promise<void> {
    const native = await this.nativePush.requestToken();
    if (native.permission === 'denied' || native.expoPushToken === null) return;
    let installationId = await this.vault.get('installationId');
    if (!installationId) {
      installationId = this.createInstallationId();
      if (!UUID.test(installationId)) throw new Error('Native installation id must be a UUID.');
      await this.vault.set('installationId', installationId);
    }
    const device = await this.api.registerPushDevice({
      installationId,
      expoPushToken: native.expoPushToken,
      platform: native.platform,
      projectUuid,
      permission: native.permission,
      appVersion,
    });
    await this.vault.set('pushDeviceId', device.id);
  }

  async signOut(): Promise<void> {
    const deviceId = await this.vault.get('pushDeviceId');
    if (deviceId) await this.api.deactivatePushDevice(deviceId);
    await Promise.all([
      this.vault.remove('statusApiToken'),
      this.vault.remove('mobileUserToken'),
      this.vault.remove('pushDeviceId'),
    ]);
  }
}

function createUuid(): string {
  const bytes = Array.from({ length: 16 }, () => Math.floor(Math.random() * 256));
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
  const hex = bytes.map((value) => value.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
