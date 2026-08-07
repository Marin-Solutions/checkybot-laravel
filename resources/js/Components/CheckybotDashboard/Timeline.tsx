import React, { useMemo } from 'react';
import type { IncidentGroup, TimelineTransition } from './contracts';
import { type DateFormatter, formatDuration, formatLocalDateTime, humanize } from './format';
import { SeverityBadge, StateBadge } from './StateBadge';
import { Badge, Card, CardContent, CardHeader } from './ui';

export function Timeline({
  transitions,
  formatDate = formatLocalDateTime,
}: {
  transitions: TimelineTransition[];
  formatDate?: DateFormatter;
}) {
  const ordered = useMemo(
    () => transitions.map((transition, index) => ({ transition, index }))
      .sort((left, right) => Date.parse(left.transition.occurred_at) - Date.parse(right.transition.occurred_at) || left.index - right.index)
      .map(({ transition }) => transition),
    [transitions],
  );

  if (ordered.length === 0) {
    return (
      <Card data-testid="empty-timeline">
        <CardContent>
          <p>No state transitions have been recorded for this monitor.</p>
        </CardContent>
      </Card>
    );
  }

  return (
    <ol aria-label="Incident timeline" className="relative space-y-4 border-l border-slate-300 pl-6">
      {ordered.map((transition) => (
        <li className="relative" data-occurred-at={transition.occurred_at} key={transition.transition_id}>
          <span aria-hidden="true" className="absolute -left-[1.72rem] top-5 h-3 w-3 rounded-full border-2 border-white bg-sky-700 ring-1 ring-slate-300" />
          <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h3 className="font-semibold">{humanize(transition.from)} → {humanize(transition.to)}</h3>
                <time className="text-sm text-slate-600" dateTime={transition.occurred_at}>{formatDate(transition.occurred_at)}</time>
              </div>
              <div className="flex flex-wrap gap-2">
                <StateBadge state={transition.to} />
                <SeverityBadge severity={transition.severity} />
              </div>
            </CardHeader>
            <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
              <p data-testid={`duration-${transition.transition_id}`}>
                <strong>Duration:</strong>{' '}
                {transition.duration_seconds === null ? 'Current · open' : `Completed · ${formatDuration(transition.duration_seconds)}`}
              </p>
              <p><strong>Reason:</strong> {transition.reason_code ? humanize(transition.reason_code) : 'Unavailable'}</p>
              <p><strong>Incident group:</strong> {transition.group_id ?? 'Not grouped'}</p>
              <p>
                <Badge className={transition.maintenance_suppressed ? 'bg-indigo-100 text-indigo-900' : 'bg-slate-100 text-slate-700'}>
                  {transition.maintenance_suppressed ? 'Maintenance suppressed' : 'Not maintenance suppressed'}
                </Badge>
              </p>
            </CardContent>
          </Card>
        </li>
      ))}
    </ol>
  );
}

export function IncidentGroups({ groups, formatDate = formatLocalDateTime }: { groups: IncidentGroup[]; formatDate?: DateFormatter }) {
  if (groups.length === 0) return <p data-testid="no-groups">No incident groups are associated with this monitor.</p>;
  const ordered = groups.map((group, index) => ({ group, index }))
    .sort((left, right) => Date.parse(left.group.opened_at) - Date.parse(right.group.opened_at) || left.index - right.index)
    .map(({ group }) => group);

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {ordered.map((group) => (
        <Card data-group-id={group.group_id} key={group.group_id}>
          <CardHeader>
            <h3 className="break-all font-semibold">Group {group.group_id}</h3>
            <p className="mt-1 text-sm text-slate-600">
              Opened {formatDate(group.opened_at)} · {group.closed_at ? `Closed ${formatDate(group.closed_at)}` : 'Current · open'}
            </p>
          </CardHeader>
          <CardContent>
            <p className="mb-2 break-all text-xs text-slate-500">Thread {group.notification_thread_key}</p>
            <h4 className="text-sm font-semibold">Affected monitors</h4>
            <ul className="mt-2 space-y-1">
              {group.affected_monitors.map((member) => (
                <li className="break-all text-sm" key={`${member.type}:${member.monitor_uuid}`}>
                  {humanize(member.type)} · {member.monitor_uuid}
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
