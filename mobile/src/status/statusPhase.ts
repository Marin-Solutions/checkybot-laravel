import { STATUS_KINDS, STATUS_STATES, deriveFreshness, type StatusSummary } from '../contracts/monitor-domain.generated';

/**
 * Presentation phase for a status summary.
 *
 * Only `healthy` may be rendered with success/green semantics. Every other phase is fail-closed
 * per the status freshness gate in `docs/release-hardening.md`: `updated_at=null`, server
 * `stale=true`, and local age greater than 900 seconds must never render healthy green.
 *
 * Mirrored for the web dashboard in `resources/js/Components/CheckybotDashboard/statusPhase.ts`;
 * `tests/Component/ReleaseHardening/StatusPhaseParity.test.ts` holds the two implementations
 * to the same answers.
 */
export type StatusPhase = 'empty' | 'stale' | 'problem' | 'healthy';

export function deriveStatusPhase(summary: StatusSummary, nowMs: number): StatusPhase {
  const monitored = STATUS_KINDS.some((kind) => STATUS_STATES.some((state) => summary.counts[kind][state] > 0));
  if (!monitored) return 'empty';
  if (deriveFreshness(summary, nowMs) === 'stale') return 'stale';
  if (STATUS_KINDS.some((kind) => summary.counts[kind].warn > 0 || summary.counts[kind].down > 0)) return 'problem';
  return 'healthy';
}
