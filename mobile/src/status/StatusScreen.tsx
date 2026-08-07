import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import type { CheckybotClient } from '../api/CheckybotApiClient';
import { STATUS_KINDS, STATUS_STATES, deriveFreshness, type StatusSummary } from '../contracts/monitor-domain.generated';

export interface SummaryCache {
  read(): StatusSummary | null;
  write(summary: StatusSummary): void;
}

export class MemorySummaryCache implements SummaryCache {
  constructor(private summary: StatusSummary | null = null) {}
  read(): StatusSummary | null { return this.summary; }
  write(summary: StatusSummary): void { this.summary = summary; }
}

type Props = {
  api: Pick<CheckybotClient, 'getStatusSummary'>;
  cache?: SummaryCache;
  now?: () => number;
  onOpenNotification?: () => void;
};

export function StatusScreen({ api, cache, now = Date.now, onOpenNotification }: Props) {
  const internalCache = useRef<SummaryCache>(new MemorySummaryCache());
  const resolvedCache = cache ?? internalCache.current;
  const [summary, setSummary] = useState<StatusSummary | null>(() => resolvedCache.read());
  const [loading, setLoading] = useState(summary === null);
  const [offline, setOffline] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');
  const request = useRef(0);

  const refresh = useCallback(async () => {
    const current = ++request.current;
    if (!summary) setLoading(true);
    try {
      const next = await api.getStatusSummary();
      if (current !== request.current) return;
      resolvedCache.write(next);
      setSummary(next);
      setOffline(false);
      setErrorMessage('');
    } catch (caught) {
      if (current !== request.current) return;
      setOffline(true);
      setErrorMessage(caught instanceof Error ? caught.message : 'Unable to refresh status.');
    } finally {
      if (current === request.current) setLoading(false);
    }
  }, [api, resolvedCache, summary]);

  useEffect(() => { void refresh(); }, []); // Initial fetch is intentionally once per mounted screen.

  const age = summary ? lastSynced(summary, now()) : null;
  const allHealthy = summary ? STATUS_KINDS.every((kind) => summary.counts[kind].warn === 0 && summary.counts[kind].down === 0) : false;

  return (
    <View style={styles.page}>
      <View style={styles.header}>
        <Text accessibilityRole="header" style={styles.title}>Project status</Text>
        <Text style={styles.subtitle}>Live health across every monitor</Text>
      </View>

      {loading && summary === null ? (
        <View accessibilityRole="progressbar" style={styles.center} testID="status-loading">
          <ActivityIndicator color="#2563eb" size="large" />
          <Text style={styles.muted}>Loading current status…</Text>
        </View>
      ) : null}

      {offline ? (
        <View accessibilityRole="alert" style={styles.offline} testID="offline-banner">
          <Text style={styles.offlineTitle}>You’re offline</Text>
          <Text style={styles.offlineText}>{summary ? 'Showing your last synced status.' : 'No saved status is available.'}</Text>
          <Text style={styles.offlineDetail}>{errorMessage}</Text>
        </View>
      ) : null}

      {summary ? (
        <View style={styles.card} testID="status-summary">
          <View style={styles.summaryHeading}>
            <Text style={styles.cardTitle}>{allHealthy ? 'Everything is healthy' : 'Problems need attention'}</Text>
            <Text testID="last-synced" style={styles.synced}>Last synced {age}</Text>
          </View>
          {deriveFreshness(summary, now()) === 'stale' ? <Text style={styles.stale}>Status data is stale</Text> : null}
          {allHealthy ? (
            <Text style={styles.healthyMessage} testID="all-healthy">No warnings or outages right now.</Text>
          ) : (
            <View testID="problem-list">
              {STATUS_KINDS.flatMap((kind) => STATUS_STATES
                .filter((state) => state !== 'healthy' && summary.counts[kind][state] > 0)
                .map((state) => (
                  <View key={`${kind}-${state}`} style={styles.problemRow} testID={`problem-${kind}-${state}`}>
                    <View style={[styles.dot, state === 'down' ? styles.down : styles.warn]} />
                    <Text style={styles.problemName}>{label(kind)} · {state === 'down' ? 'Down' : 'Warning'}</Text>
                    <Text style={styles.count}>{summary.counts[kind][state]}</Text>
                  </View>
                )))}
            </View>
          )}
        </View>
      ) : null}

      {!loading ? (
        <Pressable accessibilityRole="button" accessibilityLabel="Refresh status" onPress={() => void refresh()} style={styles.refresh}>
          <Text style={styles.refreshText}>Refresh status</Text>
        </Pressable>
      ) : null}
      {onOpenNotification && summary ? (
        <Pressable accessibilityRole="button" accessibilityLabel="Open latest notification" onPress={onOpenNotification} style={styles.notification}>
          <Text style={styles.notificationText}>Open latest notification</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

function label(kind: (typeof STATUS_KINDS)[number]): string {
  return kind === 'apis' ? 'APIs' : kind[0]!.toUpperCase() + kind.slice(1);
}

function lastSynced(summary: StatusSummary, nowMs: number): string {
  if (!summary.updated_at) return 'unknown';
  const minutes = Math.max(0, Math.floor((nowMs - Date.parse(summary.updated_at)) / 60_000));
  return `${minutes}m ago`;
}

const styles = StyleSheet.create({
  page: { backgroundColor: '#f1f5f9', flex: 1, minHeight: '100%', padding: 24 },
  header: { marginBottom: 24, marginTop: 24 },
  title: { color: '#0f172a', fontSize: 30, fontWeight: '800' },
  subtitle: { color: '#64748b', fontSize: 15, marginTop: 6 },
  center: { alignItems: 'center', flex: 1, gap: 14, justifyContent: 'center', minHeight: 300 },
  muted: { color: '#64748b', fontSize: 15 },
  offline: { backgroundColor: '#fff7ed', borderColor: '#fdba74', borderRadius: 12, borderWidth: 1, marginBottom: 16, padding: 16 },
  offlineTitle: { color: '#9a3412', fontSize: 16, fontWeight: '800' },
  offlineText: { color: '#9a3412', marginTop: 4 },
  offlineDetail: { color: '#9a3412', fontSize: 12, marginTop: 4 },
  card: { backgroundColor: '#ffffff', borderRadius: 18, padding: 20, shadowColor: '#0f172a', shadowOpacity: 0.08, shadowRadius: 18 },
  summaryHeading: { gap: 4, marginBottom: 18 },
  cardTitle: { color: '#0f172a', fontSize: 21, fontWeight: '800' },
  synced: { color: '#64748b', fontSize: 13 },
  stale: { color: '#b45309', fontWeight: '700', marginBottom: 12 },
  healthyMessage: { backgroundColor: '#ecfdf5', borderRadius: 12, color: '#047857', fontSize: 16, fontWeight: '700', padding: 18 },
  problemRow: { alignItems: 'center', borderTopColor: '#e2e8f0', borderTopWidth: 1, flexDirection: 'row', minHeight: 54 },
  dot: { borderRadius: 6, height: 12, marginRight: 12, width: 12 },
  down: { backgroundColor: '#dc2626' },
  warn: { backgroundColor: '#f59e0b' },
  problemName: { color: '#334155', flex: 1, fontSize: 15, fontWeight: '600' },
  count: { color: '#0f172a', fontSize: 19, fontWeight: '800' },
  refresh: { alignItems: 'center', backgroundColor: '#2563eb', borderRadius: 12, marginTop: 20, padding: 14 },
  refreshText: { color: '#ffffff', fontSize: 15, fontWeight: '800' },
  notification: { alignItems: 'center', borderColor: '#2563eb', borderRadius: 12, borderWidth: 1, marginTop: 12, padding: 14 },
  notificationText: { color: '#1d4ed8', fontSize: 15, fontWeight: '800' },
});
