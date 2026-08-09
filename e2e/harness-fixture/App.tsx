import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  getStatusSummary,
  StatusSummary,
} from '../../packages/contracts/generated/monitor-foundation';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';

export type ReadyResponse = {
  app: 'ready' | 'starting' | 'failed';
  queue: 'ready' | 'starting' | 'failed';
  run_id: string;
};

export type QueuedResponse = {
  status: 'queued';
  probe_id: string;
  accepted_at: string;
};

export type ProbeResponse = {
  status: 'queued' | 'processed';
  probe_id: string;
  processed_at: string | null;
};

export type HarnessApi = {
  ready(): Promise<ReadyResponse>;
  create(probeId: string): Promise<QueuedResponse>;
  probe(probeId: string): Promise<ProbeResponse>;
  summary(): Promise<StatusSummary>;
};

async function responseJson<T>(response: Response): Promise<T> {
  const body = (await response.json()) as T | { message?: string };

  if (!response.ok) {
    const message = 'message' in (body as object)
      ? (body as { message?: string }).message
      : undefined;
    throw new Error(message || `Harness API returned HTTP ${response.status}`);
  }

  return body as T;
}

export const browserHarnessApi: HarnessApi = {
  ready: async () => responseJson<ReadyResponse>(await fetch('/__harness/ready', {
    headers: { Accept: 'application/json' },
  })),
  create: async (probeId) => responseJson<QueuedResponse>(await fetch('/__harness/queue-probes', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ probe_id: probeId }),
  })),
  probe: async (probeId) => responseJson<ProbeResponse>(await fetch(`/__harness/queue-probes/${probeId}`, {
    headers: { Accept: 'application/json' },
  })),
  summary: async () => (await getStatusSummary('cbp_harness_status_read_token')).data,
};

type FixtureState = 'starting' | 'ready' | 'queued' | 'processed' | 'error';

type Props = {
  api?: HarnessApi;
  readinessPollMs?: number;
  probePollMs?: number;
  makeProbeId?: () => string;
};

function defaultProbeId(): string {
  return globalThis.crypto.randomUUID();
}

export function HarnessFixture({
  api = browserHarnessApi,
  readinessPollMs = 250,
  probePollMs = 300,
  makeProbeId = defaultProbeId,
}: Props) {
  const [state, setState] = useState<FixtureState>('starting');
  const [runId, setRunId] = useState<string>('');
  const [probeId, setProbeId] = useState<string>('');
  const [processedAt, setProcessedAt] = useState<string>('');
  const [summary, setSummary] = useState<StatusSummary | null>(null);
  const [error, setError] = useState<string>('');
  const mounted = useRef(true);
  const probeTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => () => {
    mounted.current = false;
    if (probeTimer.current) clearTimeout(probeTimer.current);
  }, []);

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;

    const check = async () => {
      try {
        const readiness = await api.ready();
        if (!mounted.current) return;

        setRunId(readiness.run_id);
        if (readiness.app === 'ready' && readiness.queue === 'ready') {
          setSummary(await api.summary());
          if (mounted.current) setState('ready');
          return;
        }
        if (readiness.app === 'failed' || readiness.queue === 'failed') {
          throw new Error('Backend runtime reported a failed state');
        }
        timer = setTimeout(check, readinessPollMs);
      } catch (caught) {
        if (!mounted.current) return;
        setError(caught instanceof Error ? caught.message : 'Unknown API error');
        setState('error');
      }
    };

    void check();
    return () => timer && clearTimeout(timer);
  }, [api, readinessPollMs]);

  const pollProbe = useCallback(async (id: string): Promise<void> => {
    try {
      const result = await api.probe(id);
      if (!mounted.current) return;

      if (result.status === 'processed' && result.processed_at) {
        setProcessedAt(result.processed_at);
        setState('processed');
        return;
      }
      probeTimer.current = setTimeout(() => void pollProbe(id), probePollMs);
    } catch (caught) {
      if (!mounted.current) return;
      setError(caught instanceof Error ? caught.message : 'Unknown API error');
      setState('error');
    }
  }, [api, probePollMs]);

  const createProbe = async () => {
    try {
      const id = makeProbeId();
      const result = await api.create(id);
      if (!mounted.current) return;

      setProbeId(result.probe_id);
      setState('queued');
      probeTimer.current = setTimeout(() => void pollProbe(result.probe_id), probePollMs);
    } catch (caught) {
      if (!mounted.current) return;
      setError(caught instanceof Error ? caught.message : 'Unknown API error');
      setState('error');
    }
  };

  return (
    <View style={styles.page}>
      <View style={styles.card}>
        <Text accessibilityRole="header" style={styles.title}>Checkybot runtime proof</Text>
        <Text style={styles.subtitle}>Expo web fixture · harness only</Text>

        <View style={styles.statusPanel}>
          <Text style={styles.label}>Runtime status</Text>
          {state === 'starting' && (
            <View style={styles.row} testID="backend-starting">
              <ActivityIndicator color="#f59e0b" />
              <Text style={styles.statusText}>Backend starting</Text>
            </View>
          )}
          {state === 'ready' && (
            <View style={styles.row} testID="backend-ready">
              <View style={[styles.dot, styles.success]} />
              <Text style={styles.statusText}>Backend ready</Text>
            </View>
          )}
          {state === 'queued' && (
            <View testID="queue-queued">
              <Text style={styles.statusText}>Queue probe queued</Text>
              <Text style={styles.mono}>{probeId}</Text>
            </View>
          )}
          {state === 'processed' && (
            <View testID="queue-processed">
              <Text style={styles.processed}>Queue probe processed</Text>
              <Text style={styles.mono}>{probeId}</Text>
              <Text style={styles.timestamp}>Processed at {processedAt}</Text>
            </View>
          )}
          {state === 'error' && (
            <View testID="api-error">
              <Text style={styles.error}>Harness API error</Text>
              <Text style={styles.errorDetail}>{error}</Text>
            </View>
          )}
        </View>

        {summary ? (
          <View style={styles.summary} testID="status-summary">
            <Text style={styles.label}>Canonical status summary</Text>
            {(['servers', 'websites', 'apis'] as const).map((type) => (
              <View key={type} style={styles.summaryRow}>
                <Text style={styles.summaryType}>{type}</Text>
                {(['healthy', 'warn', 'down'] as const).map((cell) => (
                  <Text key={cell} testID={`status-${type}-${cell}`} style={styles.summaryCell}>
                    {cell}: {summary.counts[type][cell]}
                  </Text>
                ))}
              </View>
            ))}
            <Text testID="status-updated-at" style={styles.timestamp}>Updated at {summary.updated_at ?? 'null'}</Text>
            <Text testID="status-stale" style={styles.timestamp}>Stale: {String(summary.stale)}</Text>
          </View>
        ) : null}

        {runId ? <Text style={styles.runId}>Run ID: {runId}</Text> : null}

        {state === 'ready' && (
          <Pressable
            accessibilityRole="button"
            onPress={() => void createProbe()}
            style={({ pressed }) => [styles.button, pressed && styles.buttonPressed]}
          >
            <Text style={styles.buttonText}>Create queue probe</Text>
          </Pressable>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  page: {
    alignItems: 'center',
    backgroundColor: '#0f172a',
    flex: 1,
    justifyContent: 'center',
    minHeight: '100%',
    padding: 24,
  },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 18,
    maxWidth: 620,
    padding: 32,
    shadowColor: '#020617',
    shadowOffset: { width: 0, height: 12 },
    shadowOpacity: 0.25,
    shadowRadius: 24,
    width: '100%',
  },
  title: { color: '#0f172a', fontSize: 28, fontWeight: '700' },
  subtitle: { color: '#64748b', fontSize: 15, marginTop: 6 },
  statusPanel: { backgroundColor: '#f8fafc', borderRadius: 12, marginTop: 28, padding: 20 },
  summary: { backgroundColor: '#f8fafc', borderRadius: 12, marginTop: 18, padding: 20 },
  summaryRow: { alignItems: 'center', flexDirection: 'row', gap: 12, marginTop: 8 },
  summaryType: { color: '#0f172a', fontSize: 14, fontWeight: '700', width: 72 },
  summaryCell: { color: '#334155', fontFamily: 'monospace', fontSize: 12 },
  label: { color: '#64748b', fontSize: 12, fontWeight: '700', letterSpacing: 1, marginBottom: 14, textTransform: 'uppercase' },
  row: { alignItems: 'center', flexDirection: 'row', gap: 10 },
  dot: { borderRadius: 6, height: 12, width: 12 },
  success: { backgroundColor: '#22c55e' },
  statusText: { color: '#1e293b', fontSize: 18, fontWeight: '600' },
  processed: { color: '#15803d', fontSize: 18, fontWeight: '700' },
  error: { color: '#b91c1c', fontSize: 18, fontWeight: '700' },
  errorDetail: { color: '#7f1d1d', marginTop: 6 },
  mono: { color: '#334155', fontFamily: 'monospace', fontSize: 13, marginTop: 8 },
  timestamp: { color: '#475569', fontSize: 13, marginTop: 5 },
  runId: { color: '#64748b', fontFamily: 'monospace', fontSize: 12, marginTop: 18 },
  button: { alignItems: 'center', backgroundColor: '#2563eb', borderRadius: 10, marginTop: 22, padding: 14 },
  buttonPressed: { backgroundColor: '#1d4ed8' },
  buttonText: { color: '#ffffff', fontSize: 16, fontWeight: '700' },
});
