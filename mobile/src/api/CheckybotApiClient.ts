import { parseStatusSummaryResponse, type StatusSummary } from '../contracts/monitor-domain.generated';
import type { CredentialVault } from '../security/CredentialVault';

export type DevicePermission = 'granted' | 'provisional';
export type MobilePlatform = 'ios' | 'android';

export interface PushDeviceRegistration {
  installationId: string;
  expoPushToken: string;
  platform: MobilePlatform;
  projectUuid: string;
  permission: DevicePermission;
  appVersion: string;
}

export interface RegisteredPushDevice {
  id: string;
  installationId: string;
  platform: MobilePlatform;
  projectUuid: string;
  active: true;
  registeredAt: string;
}

export interface CheckybotClient {
  getStatusSummary(): Promise<StatusSummary>;
  registerPushDevice(input: PushDeviceRegistration): Promise<RegisteredPushDevice>;
  deactivatePushDevice(deviceId: string): Promise<void>;
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/** A 401/403 from the status API. Surfaces distinctly so it is never mistaken for being offline. */
export class StatusAuthError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'StatusAuthError';
  }
}

export class CheckybotApiClient implements CheckybotClient {
  private readonly request: typeof fetch;

  constructor(
    private readonly vault: CredentialVault,
    private readonly baseUrl = '',
    request?: typeof fetch,
  ) {
    this.request = request ?? ((input, init) => globalThis.fetch(input, init));
  }

  async getStatusSummary(): Promise<StatusSummary> {
    const token = await this.requiredCredential('statusApiToken');
    const response = await this.request(`${this.baseUrl}/api/status-summary`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    // Checked before reading the body: a rejected request need not carry a JSON payload.
    if (response.status === 401 || response.status === 403) {
      throw new StatusAuthError('Your session has expired. Sign in again to see live status.');
    }
    const body: unknown = await response.json();
    if (!response.ok) throw new Error('Unable to refresh status.');
    return parseStatusSummaryResponse(body).data;
  }

  async registerPushDevice(input: PushDeviceRegistration): Promise<RegisteredPushDevice> {
    const token = await this.requiredCredential('mobileUserToken');
    const response = await this.request(`${this.baseUrl}/api/v1/push-devices`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        installation_id: input.installationId,
        expo_push_token: input.expoPushToken,
        platform: input.platform,
        project_uuid: input.projectUuid,
        permission: input.permission,
        app_version: input.appVersion,
      }),
    });
    const body: unknown = await response.json();
    if (!response.ok) throw new Error('Unable to register this device.');
    return parseRegisteredDevice(body);
  }

  async deactivatePushDevice(deviceId: string): Promise<void> {
    if (!UUID.test(deviceId)) throw new Error('Invalid push device id.');
    const token = await this.requiredCredential('mobileUserToken');
    const response = await this.request(`${this.baseUrl}/api/v1/push-devices/${deviceId}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (response.status !== 204) throw new Error('Unable to deactivate this device.');
  }

  private async requiredCredential(key: 'statusApiToken' | 'mobileUserToken'): Promise<string> {
    const value = await this.vault.get(key);
    if (!value) throw new Error('Authentication is required.');
    return value;
  }
}

function parseRegisteredDevice(value: unknown): RegisteredPushDevice {
  if (typeof value !== 'object' || value === null || !('data' in value)) throw new Error('Malformed push-device response.');
  const data = (value as { data: unknown }).data;
  if (typeof data !== 'object' || data === null) throw new Error('Malformed push-device response.');
  const item = data as Record<string, unknown>;
  if (!UUID.test(String(item.id)) || !UUID.test(String(item.installation_id)) || !UUID.test(String(item.project_uuid))
    || !['ios', 'android'].includes(String(item.platform)) || item.active !== true
    || typeof item.registered_at !== 'string' || !Number.isFinite(Date.parse(item.registered_at))) {
    throw new Error('Malformed push-device response.');
  }
  return {
    id: String(item.id),
    installationId: String(item.installation_id),
    projectUuid: String(item.project_uuid),
    platform: item.platform as MobilePlatform,
    active: true,
    registeredAt: item.registered_at,
  };
}
