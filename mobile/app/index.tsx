import { router } from 'expo-router';
import { CheckybotApiClient } from '../src/api/CheckybotApiClient';
import { notificationProblemsRoute } from '../src/routing/deepLinks';
import { createCredentialVault } from '../src/security/CredentialVault';
import { MemorySummaryCache, StatusScreen } from '../src/status/StatusScreen';

const vault = createCredentialVault();
const api = new CheckybotApiClient(vault);
const cache = new MemorySummaryCache();

declare global {
  var __CHECKYBOT_HARNESS_PROJECT_UUID__: string | undefined;
  var __CHECKYBOT_HARNESS_NOTIFICATION__: unknown;
}

export default function StatusRoute() {
  const projectUuid = globalThis.__CHECKYBOT_HARNESS_PROJECT_UUID__;
  const route = projectUuid
    ? notificationProblemsRoute(globalThis.__CHECKYBOT_HARNESS_NOTIFICATION__, projectUuid)
    : null;
  const href = route ? { pathname: route.pathname, params: route.params } : null;
  return <StatusScreen api={api} cache={cache} onOpenNotification={href ? () => router.push(href as never) : undefined} />;
}
