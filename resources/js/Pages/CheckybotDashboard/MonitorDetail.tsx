import React from 'react';
import { Annotations } from '../../Components/CheckybotDashboard/Annotations';
import type { MonitorDetailProps } from '../../Components/CheckybotDashboard/contracts';
import { type DateFormatter, formatLocalDateTime, humanize } from '../../Components/CheckybotDashboard/format';
import { StateBadge } from '../../Components/CheckybotDashboard/StateBadge';
import { IncidentGroups, Timeline } from '../../Components/CheckybotDashboard/Timeline';

export default function MonitorDetail({
  monitor,
  timeline,
  formatDate = formatLocalDateTime,
}: MonitorDetailProps & { formatDate?: DateFormatter }) {
  return (
    <main className="min-h-screen bg-slate-50 text-slate-950">
      <div className="mx-auto flex max-w-5xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
        <header>
          <a className="text-sm font-medium text-sky-800 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-sky-600" href="/checkybot">← Monitor overview</a>
          <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
              <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">{humanize(monitor.identity.type)} monitor</p>
              <h1 className="break-all text-2xl font-bold">{monitor.identity.monitor_id}</h1>
              <p className="mt-1 text-sm text-slate-600">Current state entered {formatDate(monitor.entered_at)}</p>
            </div>
            <StateBadge state={monitor.current_state} />
          </div>
        </header>

        <section aria-labelledby="timeline-heading">
          <h2 className="mb-4 text-xl font-bold" id="timeline-heading">Incident timeline</h2>
          <Timeline formatDate={formatDate} transitions={timeline.transitions} />
        </section>

        <section aria-labelledby="groups-heading">
          <h2 className="mb-4 text-xl font-bold" id="groups-heading">Incident groups</h2>
          <IncidentGroups formatDate={formatDate} groups={timeline.incident_groups} />
        </section>

        <section aria-labelledby="annotations-heading">
          <h2 className="mb-4 text-xl font-bold" id="annotations-heading">Annotations</h2>
          <Annotations slots={timeline.annotation_slots} />
        </section>
      </div>
    </main>
  );
}
