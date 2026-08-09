import React, { useEffect, useState } from 'react';
import ApiAssertionBuilder from '../../../../resources/js/Pages/CheckybotDashboard/ApiAssertionBuilder';
import MonitorDetail from '../../../../resources/js/Pages/CheckybotDashboard/MonitorDetail';
import Overview from '../../../../resources/js/Pages/CheckybotDashboard/Overview';
import type { ApiAssertionBuilderProps } from '../../../../resources/js/Components/CheckybotDashboard/api-builder-contracts';
import type { MonitorDetailProps, OverviewProps } from '../../../../resources/js/Components/CheckybotDashboard/contracts';

const query = new URLSearchParams(window.location.search);
const backend = query.get('backend') ?? '';
const project = query.get('project') ?? '';
const apiMonitor = query.get('api_monitor') ?? '';

type Page =
  | { component: 'CheckybotDashboard/Overview'; props: OverviewProps }
  | { component: 'CheckybotDashboard/MonitorDetail'; props: MonitorDetailProps }
  | { component: 'CheckybotDashboard/ApiAssertionBuilder'; props: ApiAssertionBuilderProps };

function xsrfToken(): string | null {
  const item = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
  return item ? decodeURIComponent(item.slice('XSRF-TOKEN='.length)) : null;
}

async function request(path: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  const token = xsrfToken();
  if (token) headers.set('X-XSRF-TOKEN', token);
  return fetch(`${backend}${path}`, { ...init, headers, credentials: 'include' });
}

async function inertia(path: string): Promise<Page> {
  const response = await request(path, { headers: { Accept: 'application/json', 'X-Inertia': 'true' } });
  if (!response.ok) throw new Error(`Inertia request failed with HTTP ${response.status}`);
  return response.json();
}

export default function App() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);

  const showOverview = async () => setPage(await inertia('/checkybot'));
  const showBuilder = async () => setPage(await inertia(`/checkybot/api-monitors/${apiMonitor}/assertions`));

  useEffect(() => {
    void (async () => {
      const auth = await request(`/__harness/web-dashboard/authenticate/${project}`, { headers: { Accept: 'application/json' } });
      if (!auth.ok) throw new Error(`Harness authentication failed with HTTP ${auth.status}`);
      await showOverview();
    })().catch((failure: Error) => setError(failure.message));
  }, []);

  useEffect(() => {
    const followDetail = (event: MouseEvent) => {
      const anchor = (event.target as HTMLElement | null)?.closest<HTMLAnchorElement>('a[href^="/checkybot/monitors/"]');
      if (!anchor) return;
      event.preventDefault();
      void inertia(anchor.getAttribute('href') ?? '').then(setPage).catch((failure: Error) => setError(failure.message));
    };
    document.addEventListener('click', followDetail);
    return () => document.removeEventListener('click', followDetail);
  }, []);

  if (error) return <div role="alert">{error}</div>;
  if (!page) return <div role="status">Authenticating runtime operator…</div>;

  return (
    <div>
      <nav aria-label="Runtime journey" style={{ display: 'flex', gap: 8, padding: 8 }}>
        <button onClick={() => void showOverview()}>Dashboard</button>
        <button onClick={() => void showBuilder()}>API assertion builder</button>
      </nav>
      {page.component === 'CheckybotDashboard/Overview' ? (
        <Overview
          {...page.props}
          visit={async (path) => (await inertia(path) as Extract<Page, { component: 'CheckybotDashboard/Overview' }>).props}
        />
      ) : null}
      {page.component === 'CheckybotDashboard/MonitorDetail' ? <MonitorDetail {...page.props} /> : null}
      {page.component === 'CheckybotDashboard/ApiAssertionBuilder' ? (
        <ApiAssertionBuilder
          {...page.props}
          request={(path, init) => request(path, init)}
          reload={() => void showBuilder()}
        />
      ) : null}
    </div>
  );
}
