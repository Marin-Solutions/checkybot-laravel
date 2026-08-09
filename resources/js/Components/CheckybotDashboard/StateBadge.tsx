import React from 'react';
import type { LifecycleState, Severity } from './contracts';
import { humanize } from './format';
import { Badge } from './ui';

const stateTone: Record<LifecycleState, string> = {
  healthy: 'bg-emerald-100 text-emerald-800',
  warn: 'bg-amber-100 text-amber-900',
  down: 'bg-rose-100 text-rose-800',
  recovering: 'bg-sky-100 text-sky-800',
};

export function StateBadge({ state }: { state: LifecycleState }) {
  return <Badge className={stateTone[state]} data-state={state}>{humanize(state)}</Badge>;
}

export function SeverityBadge({ severity }: { severity: Severity }) {
  const tone = severity === 'critical' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-900';
  const label = severity === 'warn' ? 'Warning' : humanize(severity);
  return <Badge className={tone} data-severity={severity}>{label} severity</Badge>;
}
