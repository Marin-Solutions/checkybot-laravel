import React from 'react';
import type { StatusSummary } from './contracts';
import { humanize } from './format';
import { Card } from './ui';

const rows = [
  ['servers', 'Servers'],
  ['websites', 'Websites'],
  ['apis', 'APIs'],
] as const;
const columns = [
  ['healthy', 'Healthy'],
  ['warn', 'Warning'],
  ['down', 'Down'],
] as const;

export function StatusGrid({ counts }: { counts: StatusSummary['counts'] }) {
  return (
    <dl aria-label="Monitor status summary" className="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-9">
      {rows.flatMap(([row, rowLabel]) => columns.map(([column, columnLabel]) => (
        <Card
          key={`${row}-${column}`}
          className="min-w-0 px-4 py-3"
          data-testid={`status-${row}-${column}`}
        >
          <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
            {rowLabel} · {columnLabel}
          </dt>
          <dd
            aria-label={`${rowLabel} ${humanize(column)}: ${counts[row][column]}`}
            className="mt-1 text-2xl font-bold tabular-nums text-slate-950"
          >
            {counts[row][column]}
          </dd>
        </Card>
      )))}
    </dl>
  );
}
