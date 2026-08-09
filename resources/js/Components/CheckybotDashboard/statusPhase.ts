import type { StatusSummary } from './contracts';

/**
 * Web mirror of `mobile/src/status/statusPhase.ts`. The mobile bundle is built by Metro rooted at
 * `mobile/`, so it cannot import from this tree; the two copies are held identical by
 * `tests/Component/ReleaseHardening/StatusPhaseParity.test.ts`.
 *
 * Only `healthy` may be rendered with success/green semantics — see the status freshness gate in
 * `docs/release-hardening.md`.
 */
export type StatusPhase = 'empty' | 'stale' | 'problem' | 'healthy';

const KINDS = ['servers', 'websites', 'apis'] as const;
const STATES = ['healthy', 'warn', 'down'] as const;

/** Mirrors `deriveFreshness()` in `mobile/src/contracts/monitor-domain.generated.ts`. */
export function isStale(summary: StatusSummary, nowMs: number): boolean {
  if (summary.stale || summary.updated_at === null) return true;
  return Math.max(0, (nowMs - Date.parse(summary.updated_at)) / 1000) > 900;
}

export function deriveStatusPhase(summary: StatusSummary, nowMs: number): StatusPhase {
  const monitored = KINDS.some((kind) => STATES.some((state) => summary.counts[kind][state] > 0));
  if (!monitored) return 'empty';
  if (isStale(summary, nowMs)) return 'stale';
  if (KINDS.some((kind) => summary.counts[kind].warn > 0 || summary.counts[kind].down > 0)) return 'problem';
  return 'healthy';
}
