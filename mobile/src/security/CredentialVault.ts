import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

export type CredentialKey = 'statusApiToken' | 'mobileUserToken' | 'installationId' | 'pushDeviceId';

export interface CredentialVault {
  get(key: CredentialKey): Promise<string | null>;
  set(key: CredentialKey, value: string): Promise<void>;
  remove(key: CredentialKey): Promise<void>;
}

const iosOptions: SecureStore.SecureStoreOptions = {
  accessGroup: 'group.dev.checkybot.status',
  keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

export class ExpoSecureCredentialVault implements CredentialVault {
  async get(key: CredentialKey): Promise<string | null> {
    return SecureStore.getItemAsync(key, Platform.OS === 'ios' ? iosOptions : undefined);
  }

  async set(key: CredentialKey, value: string): Promise<void> {
    await SecureStore.setItemAsync(key, value, Platform.OS === 'ios' ? iosOptions : undefined);
  }

  async remove(key: CredentialKey): Promise<void> {
    await SecureStore.deleteItemAsync(key, Platform.OS === 'ios' ? iosOptions : undefined);
  }
}

declare global {
  // Playwright injects this test-only native-vault stand-in before the web shell starts.
  var __CHECKYBOT_HARNESS_VAULT__: Partial<Record<CredentialKey, string>> | undefined;
}

export class BrowserHarnessCredentialVault implements CredentialVault {
  private values(): Partial<Record<CredentialKey, string>> {
    if (process.env.EXPO_PUBLIC_CHECKYBOT_HARNESS !== 'true') return {};
    return globalThis.__CHECKYBOT_HARNESS_VAULT__ ?? {};
  }

  async get(key: CredentialKey): Promise<string | null> {
    return this.values()[key] ?? null;
  }

  async set(key: CredentialKey, value: string): Promise<void> {
    if (process.env.EXPO_PUBLIC_CHECKYBOT_HARNESS !== 'true') throw new Error('Secure storage is unavailable on web.');
    globalThis.__CHECKYBOT_HARNESS_VAULT__ = { ...this.values(), [key]: value };
  }

  async remove(key: CredentialKey): Promise<void> {
    const values = { ...this.values() };
    delete values[key];
    globalThis.__CHECKYBOT_HARNESS_VAULT__ = values;
  }
}

export function createCredentialVault(): CredentialVault {
  return Platform.OS === 'web' ? new BrowserHarnessCredentialVault() : new ExpoSecureCredentialVault();
}
