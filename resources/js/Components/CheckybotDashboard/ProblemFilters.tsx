import React from 'react';
import type { DashboardFilters, MonitorType, ProblemState, Severity } from './contracts';
import { humanize } from './format';
import { Card, CardContent, CardHeader } from './ui';

const typeOptions: readonly MonitorType[] = ['server', 'website', 'api'];
const stateOptions: readonly ProblemState[] = ['warn', 'down', 'recovering'];
const severityOptions: readonly Severity[] = ['warn', 'critical'];

function unique<T extends string>(values: readonly T[]): T[] {
  return [...new Set(values)];
}

export function normalizedFilters(filters: DashboardFilters): DashboardFilters {
  return {
    types: unique(filters.types.filter((value) => typeOptions.includes(value))),
    states: unique(filters.states.filter((value) => stateOptions.includes(value))),
    severities: unique(filters.severities.filter((value) => severityOptions.includes(value))),
    monitor_uuids: unique(filters.monitor_uuids.map((value) => value.toLowerCase())),
  };
}

export function dashboardQuery(filters: DashboardFilters): string {
  const params = new URLSearchParams();
  const normalized = normalizedFilters(filters);
  normalized.types.forEach((value) => params.append('types[]', value));
  normalized.states.forEach((value) => params.append('states[]', value));
  normalized.severities.forEach((value) => params.append('severities[]', value));
  normalized.monitor_uuids.forEach((value) => params.append('monitor_uuids[]', value));
  const query = params.toString();
  return query ? `/checkybot?${query}` : '/checkybot';
}

function FilterGroup<T extends string>({
  label,
  name,
  options,
  selected,
  disabled,
  onChange,
}: {
  label: string;
  name: string;
  options: readonly T[];
  selected: readonly T[];
  disabled: boolean;
  onChange: (next: T[]) => void | Promise<void>;
}) {
  return (
    <fieldset className="min-w-0" disabled={disabled}>
      <legend className="mb-2 text-sm font-semibold text-slate-900">{label}</legend>
      <div className="flex flex-wrap gap-2">
        {options.map((option) => {
          const checked = selected.includes(option);
          return (
            <label
              className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm outline-none has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-sky-600"
              key={option}
            >
              <input
                checked={checked}
                name={`${name}[]`}
                onChange={() => onChange(checked ? selected.filter((item) => item !== option) : [...selected, option])}
                type="checkbox"
                value={option}
              />
              {humanize(option)}
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}

export function ProblemFilters({
  filters,
  disabled = false,
  onChange,
}: {
  filters: DashboardFilters;
  disabled?: boolean;
  onChange: (next: DashboardFilters) => void | Promise<void>;
}) {
  const selected = normalizedFilters(filters);
  return (
    <Card aria-label="Problem filters">
      <CardHeader>
        <h2 className="text-lg font-semibold text-slate-950">Filter problems</h2>
      </CardHeader>
      <CardContent className="grid gap-5 lg:grid-cols-3">
        <FilterGroup
          disabled={disabled}
          label="Monitor type"
          name="types"
          onChange={(types) => onChange({ ...selected, types })}
          options={typeOptions}
          selected={selected.types}
        />
        <FilterGroup
          disabled={disabled}
          label="State"
          name="states"
          onChange={(states) => onChange({ ...selected, states })}
          options={stateOptions}
          selected={selected.states}
        />
        <FilterGroup
          disabled={disabled}
          label="Severity"
          name="severities"
          onChange={(severities) => onChange({ ...selected, severities })}
          options={severityOptions}
          selected={selected.severities}
        />
      </CardContent>
      {selected.monitor_uuids.length > 0 ? (
        <div className="border-t border-slate-100 px-5 py-3" data-testid="monitor-filter-summary">
          <span className="text-sm font-medium text-slate-700">Notification monitor filter:</span>{' '}
          {selected.monitor_uuids.map((uuid) => <code className="mr-2 text-xs" key={uuid}>{uuid}</code>)}
        </div>
      ) : null}
    </Card>
  );
}
