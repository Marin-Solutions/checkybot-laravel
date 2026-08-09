import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { OverviewProps } from '../../Components/CheckybotDashboard/contracts';
import { type DateFormatter, formatLocalDateTime } from '../../Components/CheckybotDashboard/format';
import { MaintenanceBanner } from '../../Components/CheckybotDashboard/MaintenanceBanner';
import { ProblemFilters, dashboardQuery, normalizedFilters } from '../../Components/CheckybotDashboard/ProblemFilters';
import { ProblemList } from '../../Components/CheckybotDashboard/ProblemList';
import { StatusGrid } from '../../Components/CheckybotDashboard/StatusGrid';
import { deriveStatusPhase, isStale } from '../../Components/CheckybotDashboard/statusPhase';
import { Card, CardContent } from '../../Components/CheckybotDashboard/ui';

export type OverviewVisit = (url: string, options?: { replace?: boolean }) => Promise<OverviewProps>;

async function inertiaVisit(url: string, _options: { replace?: boolean } = {}): Promise<OverviewProps> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Inertia': 'true' },
  });
  if (!response.ok) throw new Error(`Dashboard request returned HTTP ${response.status}`);
  const page = await response.json() as { component?: string; props?: OverviewProps };
  if (page.component !== 'CheckybotDashboard/Overview' || !page.props) {
    throw new Error('Dashboard request returned an invalid Inertia page.');
  }
  return page.props;
}

function commitBrowserUrl(url: string, replace: boolean): void {
  if (typeof window === 'undefined') return;
  window.history[replace ? 'replaceState' : 'pushState']({}, '', url);
}

function SummaryMessage({ props, formatDate, nowMs }: { props: OverviewProps; formatDate: DateFormatter; nowMs: number }) {
  const phase = deriveStatusPhase(props.summary, nowMs);
  if (phase === 'empty') {
    return <p data-testid="empty-project">No monitors have been added to this project yet.</p>;
  }
  if (phase === 'stale') {
    return (
      <p className="font-medium text-amber-900" data-testid="stale-summary" role="status">
        Status data is stale. {props.summary.updated_at ? `Last update ${formatDate(props.summary.updated_at)}.` : 'No recent update is available.'}
      </p>
    );
  }
  if (phase === 'healthy' && props.problems.length === 0) {
    return <p className="font-medium text-emerald-800" data-testid="all-healthy">Everything is healthy. No warnings or outages right now.</p>;
  }
  return <p className="font-medium text-rose-800" data-testid="problem-summary">Problems need attention. Review the returned monitors below.</p>;
}

export default function Overview(initialProps: OverviewProps & {
  visit?: OverviewVisit;
  formatDate?: DateFormatter;
  now?: () => number;
}) {
  const {
    visit = inertiaVisit,
    formatDate = formatLocalDateTime,
    now = Date.now,
    filters,
    maintenance,
    pagination,
    problems,
    summary,
  } = initialProps;
  const [page, setPage] = useState<OverviewProps>(() => ({
    filters: normalizedFilters(filters),
    maintenance,
    pagination,
    problems,
    summary,
  }));
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const navigationSequence = useRef(0);
  const mounted = useRef(false);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      navigationSequence.current += 1;
    };
  }, []);

  useEffect(() => {
    navigationSequence.current += 1;
    setPage({ filters: normalizedFilters(filters), maintenance, pagination, problems, summary });
    setPending(false);
    setError(null);
  }, [filters, maintenance, pagination, problems, summary]);

  const navigate = useCallback(async (
    url: string,
    options: { replace?: boolean; optimisticFilters?: OverviewProps['filters'] } = {},
  ): Promise<void> => {
    const sequence = ++navigationSequence.current;
    if (options.optimisticFilters) {
      const optimistic = normalizedFilters(options.optimisticFilters);
      setPage((current) => ({ ...current, filters: optimistic }));
    }
    setPending(true);
    setError(null);

    try {
      const props = await visit(url, { replace: options.replace });
      if (!mounted.current || sequence !== navigationSequence.current) return;
      setPage({ ...props, filters: normalizedFilters(props.filters) });
      commitBrowserUrl(url, options.replace === true);
    } catch {
      if (!mounted.current || sequence !== navigationSequence.current) return;
      setError('The dashboard could not be refreshed. Your previous results remain visible.');
    } finally {
      if (mounted.current && sequence === navigationSequence.current) setPending(false);
    }
  }, [visit]);

  useEffect(() => {
    if (typeof window === 'undefined') return undefined;
    const restore = () => {
      void navigate(`${window.location.pathname}${window.location.search}`, { replace: true });
    };
    window.addEventListener('popstate', restore);
    return () => window.removeEventListener('popstate', restore);
  }, [navigate]);

  const nowMs = now();
  const freshness = useMemo(() => {
    if (!page.summary.updated_at) return 'Freshness unavailable';
    return `${isStale(page.summary, nowMs) ? 'Stale' : 'Updated'} ${formatDate(page.summary.updated_at)}`;
  }, [formatDate, nowMs, page.summary]);

  const applyFilters = async (nextFilters: OverviewProps['filters']) => {
    const normalized = normalizedFilters(nextFilters);
    await navigate(dashboardQuery(normalized), { optimisticFilters: normalized });
  };

  return (
    <main className="min-h-screen bg-slate-50 text-slate-950">
      <MaintenanceBanner formatDate={formatDate} maintenance={page.maintenance} />
      <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
        <header>
          <p className="text-sm font-semibold uppercase tracking-wide text-sky-700">Checkybot</p>
          <h1 className="mt-1 text-3xl font-bold tracking-tight">Monitor overview</h1>
          <p className="mt-2 text-sm text-slate-600" data-testid="freshness">{freshness}</p>
        </header>

        <StatusGrid counts={page.summary.counts} />
        <Card aria-live="polite">
          <CardContent>
            <SummaryMessage formatDate={formatDate} nowMs={nowMs} props={page} />
          </CardContent>
        </Card>
        <ProblemFilters disabled={pending} filters={page.filters} onChange={applyFilters} />
        {pending ? <div aria-live="polite" data-testid="dashboard-loading" role="progressbar">Loading filtered problems…</div> : null}
        {error ? <div className="rounded-md border border-rose-300 bg-rose-50 p-4 text-rose-900" data-testid="dashboard-error" role="alert">{error}</div> : null}
        <ProblemList formatDate={formatDate} problems={page.problems} />
      </div>
    </main>
  );
}
