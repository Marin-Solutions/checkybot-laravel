import React from 'react';
import type { Problem } from './contracts';
import { type DateFormatter, formatLocalDateTime, humanize } from './format';
import { SeverityBadge, StateBadge } from './StateBadge';
import { Card, CardContent, CardHeader } from './ui';

export function ProblemList({
  problems,
  formatDate = formatLocalDateTime,
}: {
  problems: Problem[];
  formatDate?: DateFormatter;
}) {
  if (problems.length === 0) return null;

  return (
    <Card data-testid="problems-present">
      <CardHeader>
        <h2 className="text-lg font-semibold text-slate-950">Current problems</h2>
        <p className="mt-1 text-sm text-slate-600">Only monitors returned for the applied filters are shown.</p>
      </CardHeader>
      <CardContent>
        <ul className="divide-y divide-slate-100" aria-label="Current monitor problems">
          {problems.map((problem) => (
            <li className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between" key={`${problem.identity.type}:${problem.identity.monitor_id}`}>
              <div className="min-w-0">
                <a
                  className="break-all font-semibold text-sky-800 underline-offset-4 outline-none hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-sky-600"
                  href={problem.detail_url}
                >
                  {humanize(problem.identity.type)} {problem.identity.monitor_id}
                </a>
                <p className="mt-1 text-sm text-slate-600">Observed {formatDate(problem.observed_at)}</p>
              </div>
              <div className="flex flex-wrap gap-2">
                <StateBadge state={problem.state} />
                <SeverityBadge severity={problem.severity} />
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
