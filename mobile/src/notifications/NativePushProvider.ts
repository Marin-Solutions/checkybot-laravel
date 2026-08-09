import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import type { DevicePermission, MobilePlatform } from '../api/CheckybotApiClient';

export interface PushPermissionResult {
  permission: DevicePermission | 'denied';
  expoPushToken: string | null;
  platform: MobilePlatform;
}

export interface PushTokenProvider {
  requestToken(): Promise<PushPermissionResult>;
}

export class ExpoPushTokenProvider implements PushTokenProvider {
  async requestToken(): Promise<PushPermissionResult> {
    if (Platform.OS !== 'ios' && Platform.OS !== 'android') {
      return { permission: 'denied', expoPushToken: null, platform: 'android' };
    }
    const result = await Notifications.requestPermissionsAsync();
    const provisional = result.ios?.status === Notifications.IosAuthorizationStatus.PROVISIONAL;
    const permission: DevicePermission | 'denied' = result.granted ? 'granted' : provisional ? 'provisional' : 'denied';
    if (permission === 'denied') return { permission, expoPushToken: null, platform: Platform.OS };
    const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;
    const token = await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined);
    return { permission, expoPushToken: token.data, platform: Platform.OS };
  }
}
