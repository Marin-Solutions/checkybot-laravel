import React from 'react';
import type { Maintenance } from './contracts';
import { type DateFormatter, formatLocalDateTime, humanize } from './format';

export function MaintenanceBanner({
  maintenance,
  formatDate = formatLocalDateTime,
}: {
  maintenance: Maintenance;
  formatDate?: DateFormatter;
}) {
  if (!maintenance.silenced || !maintenance.effective_scope || !maintenance.ends_at) return null;

  const scope = humanize(maintenance.effective_scope);
  const endsAt = formatDate(maintenance.ends_at);

  return (
    <aside
      aria-label={`${scope} maintenance window`}
      className="sticky top-0 z-20 mb-6 flex flex-col gap-1 border-b border-indigo-300 bg-indigo-950 px-4 py-3 text-sm text-white shadow-md outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-inset sm:flex-row sm:items-center sm:justify-between sm:px-6"
      data-testid="maintenance-banner"
      role="status"
      tabIndex={0}
    >
      <strong>{scope} maintenance active</strong>
      <span>Notifications silenced until {endsAt}.</span>
      {maintenance.reason ? <span className="text-indigo-100">{maintenance.reason}</span> : null}
    </aside>
  );
}
