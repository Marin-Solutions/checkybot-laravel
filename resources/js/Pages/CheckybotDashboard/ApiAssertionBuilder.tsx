import React, { useEffect, useMemo, useRef, useState } from 'react';
import { AssertionEditor, assertionPayload } from '../../Components/CheckybotDashboard/AssertionEditor';
import type {
  ApiAssertionBuilderProps,
  ApiBuilderConfiguration,
  ApiErrorPayload,
  ApiSample,
  AssertionDraft,
  HeaderDraft,
  MaskedHeader,
} from '../../Components/CheckybotDashboard/api-builder-contracts';
import { HeaderEditor } from '../../Components/CheckybotDashboard/HeaderEditor';
import { JsonPathPicker } from '../../Components/CheckybotDashboard/JsonPathPicker';
import { Button, Card, CardContent, CardHeader } from '../../Components/CheckybotDashboard/ui';

type JsonResponse = {
  ok: boolean;
  status: number;
  json: () => Promise<unknown>;
};

export type BuilderRequest = (url: string, init: RequestInit) => Promise<JsonResponse>;

const defaultRequest: BuilderRequest = (url, init) => fetch(url, init);

function csrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? null;
}

function requestHeaders(): Record<string, string> {
  const token = csrfToken();
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
  };
}

function boundedMessage(value: unknown, fallback: string): string {
  if (typeof value !== 'string' || value.trim() === '') return fallback;
  return value.slice(0, 500);
}

function headerDrafts(headers: MaskedHeader[]): HeaderDraft[] {
  return headers.map((header, index) => ({
    id: `stored-header-${index}`,
    name: header.name,
    mask: header.mask,
    hasValue: header.has_value === true,
    action: 'preserve',
    replacement: '',
  }));
}

function assertionDrafts(assertions: ApiBuilderConfiguration['assertions']): AssertionDraft[] {
  return assertions.map((assertion, index) => ({ ...assertion, id: `assertion-${index}` }));
}

export function configurationPayload(
  endpoint: string,
  method: ApiBuilderConfiguration['method'],
  headers: HeaderDraft[],
  assertions: AssertionDraft[],
  version: number,
) {
  return {
    endpoint,
    method,
    headers: headers.map((header) => ({
      name: header.name,
      action: header.action,
      ...(header.action === 'set' ? { value: header.replacement } : {}),
    })),
    assertions: assertions.map(assertionPayload),
    configuration_version: version,
  };
}

function errorDetails(payload: ApiErrorPayload, status: number, fallback: string): { message: string; code: string | null } {
  if (payload.error) {
    return {
      code: payload.error.code,
      message: boundedMessage(payload.error.message, fallback),
    };
  }
  return {
    code: status === 409 ? 'configuration_conflict' : status === 422 ? 'validation_failed' : null,
    message: boundedMessage(payload.message, fallback),
  };
}

export default function ApiAssertionBuilder({
  monitor,
  configuration,
  request = defaultRequest,
  reload = () => { if (typeof window !== 'undefined') window.location.reload(); },
}: ApiAssertionBuilderProps & {
  request?: BuilderRequest;
  reload?: () => void;
}) {
  const [endpoint, setEndpoint] = useState(configuration.endpoint);
  const [method, setMethod] = useState(configuration.method);
  const [version, setVersion] = useState(configuration.version);
  const [headers, setHeaders] = useState<HeaderDraft[]>(() => headerDrafts(configuration.headers));
  const [assertions, setAssertions] = useState<AssertionDraft[]>(() => assertionDrafts(configuration.assertions));
  const [sample, setSample] = useState<ApiSample | null>(null);
  const [sampleError, setSampleError] = useState<{ message: string; code: string | null } | null>(null);
  const [saveError, setSaveError] = useState<{ message: string; stale: boolean } | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [sampling, setSampling] = useState(false);
  const [saving, setSaving] = useState(false);
  const [pickerTarget, setPickerTarget] = useState<number | null>(null);
  const mounted = useRef(true);
  const incomingVersion = useRef(configuration.version);
  const sampleSequence = useRef(0);
  const saveSequence = useRef(0);

  useEffect(() => () => {
    mounted.current = false;
    sampleSequence.current += 1;
    saveSequence.current += 1;
  }, []);

  useEffect(() => {
    if (incomingVersion.current === configuration.version) return;
    incomingVersion.current = configuration.version;
    setEndpoint(configuration.endpoint);
    setMethod(configuration.method);
    setVersion(configuration.version);
    setHeaders(headerDrafts(configuration.headers));
    setAssertions(assertionDrafts(configuration.assertions));
    setFieldErrors({});
    setSaveError(null);
  }, [configuration]);

  const baseUrl = `/checkybot/api-monitors/${encodeURIComponent(monitor.monitor_id)}`;
  const payload = useMemo(
    () => configurationPayload(endpoint, method, headers, assertions, version),
    [assertions, endpoint, headers, method, version],
  );

  const fetchSample = async () => {
    const sequence = ++sampleSequence.current;
    setSampling(true);
    setSampleError(null);
    try {
      const response = await request(`${baseUrl}/sample`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: requestHeaders(),
        body: JSON.stringify({
          endpoint: payload.endpoint,
          method: payload.method,
          headers: payload.headers,
          request_body: null,
          configuration_version: payload.configuration_version,
        }),
      });
      const body = await response.json() as { data?: ApiSample } & ApiErrorPayload;
      if (!mounted.current || sequence !== sampleSequence.current) return;
      if (!response.ok || !body.data) {
        setSampleError(errorDetails(body, response.status, 'The sample could not be fetched.'));
        return;
      }
      setSample(body.data);
      setSampleError(null);
    } catch {
      if (mounted.current && sequence === sampleSequence.current) {
        setSampleError({ code: 'fetch_failed', message: 'The sample could not be fetched.' });
      }
    } finally {
      if (mounted.current && sequence === sampleSequence.current) setSampling(false);
    }
  };

  const saveConfiguration = async () => {
    const sequence = ++saveSequence.current;
    setSaving(true);
    setSaveError(null);
    setFieldErrors({});
    try {
      const response = await request(`${baseUrl}/assertions`, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: requestHeaders(),
        body: JSON.stringify(payload),
      });
      const body = await response.json() as { data?: ApiBuilderConfiguration } & ApiErrorPayload;
      if (!mounted.current || sequence !== saveSequence.current) return;
      if (!response.ok || !body.data) {
        setFieldErrors(body.errors ?? {});
        setSaveError({
          message: boundedMessage(body.message, 'The configuration could not be saved.'),
          stale: response.status === 409,
        });
        return;
      }

      // Only the server's normalized, masked representation becomes the new local baseline.
      setEndpoint(body.data.endpoint);
      setMethod(body.data.method);
      setVersion(body.data.version);
      setHeaders(headerDrafts(body.data.headers));
      setAssertions(assertionDrafts(body.data.assertions));
      setFieldErrors({});
      setSaveError(null);
    } catch {
      if (mounted.current && sequence === saveSequence.current) {
        setSaveError({ message: 'The configuration could not be saved.', stale: false });
      }
    } finally {
      if (mounted.current && sequence === saveSequence.current) setSaving(false);
    }
  };

  const insertPath = (path: string) => {
    setAssertions((current) => {
      if (pickerTarget !== null && current[pickerTarget]?.kind === 'json_path') {
        return current.map((assertion, index) => index === pickerTarget ? { ...assertion, path } : assertion);
      }
      return [...current, {
        id: `sample-path-${Date.now()}-${current.length}`,
        kind: 'json_path',
        operator: 'exists',
        path,
      }];
    });
    setPickerTarget(null);
  };

  const renderedJson = sample ? JSON.stringify(sample.json, null, 2).slice(0, 262_144) : '';

  return (
    <main className="min-h-screen bg-slate-50 text-slate-950">
      <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
        <header>
          <p className="text-sm font-semibold uppercase tracking-wide text-sky-700">Checkybot API monitor</p>
          <h1 className="mt-1 text-3xl font-bold tracking-tight">Assertion builder</h1>
          <p className="mt-2 text-sm text-slate-600">{monitor.monitor_id} · configuration version {version}</p>
        </header>

        <Card>
          <CardHeader><h2 className="font-semibold">Request</h2></CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-[10rem_1fr]">
            <label className="text-sm">
              Method
              <select aria-label="Request method" className="mt-1 block min-h-10 w-full rounded-md border px-3" onChange={(event) => setMethod(event.currentTarget.value as ApiBuilderConfiguration['method'])} value={method}>
                {(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const).map((value) => <option key={value}>{value}</option>)}
              </select>
            </label>
            <label className="text-sm">
              Endpoint URL
              <input aria-label="Endpoint URL" className="mt-1 block min-h-10 w-full rounded-md border px-3" onChange={(event) => setEndpoint(event.currentTarget.value)} type="url" value={endpoint} />
            </label>
          </CardContent>
        </Card>

        <HeaderEditor headers={headers} onChange={setHeaders} />
        <AssertionEditor
          assertions={assertions}
          errors={fieldErrors}
          onChange={setAssertions}
          onChoosePath={(index) => {
            setPickerTarget(index);
            if (typeof document !== 'undefined') document.querySelector<HTMLElement>('[role="treeitem"]')?.focus();
          }}
        />

        <div className="flex flex-wrap gap-3">
          <Button disabled={sampling} onClick={() => void fetchSample()}>{sampling ? 'Fetching sample…' : 'Fetch live sample'}</Button>
          <Button className="border-sky-700 bg-sky-700 text-white hover:bg-sky-800" disabled={saving} onClick={() => void saveConfiguration()}>
            {saving ? 'Saving…' : 'Save configuration'}
          </Button>
        </div>

        {sampleError ? (
          <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-amber-950" data-testid="sample-error" role="alert">
            {sampleError.code ? <strong>{sampleError.code}: </strong> : null}{sampleError.message}
            <p className="mt-1 text-sm">Manual JSON-path entry remains available below.</p>
          </div>
        ) : null}
        {saveError ? (
          <div className="rounded-md border border-rose-300 bg-rose-50 p-4 text-rose-950" data-testid="save-error" role="alert">
            {saveError.message}
            {saveError.stale ? <Button className="ml-3" onClick={reload}>Reload saved configuration</Button> : null}
          </div>
        ) : null}

        {sample ? (
          <section className="grid gap-6 lg:grid-cols-2" aria-label="Live sample result">
            <Card>
              <CardHeader>
                <h2 className="font-semibold">Live response</h2>
                <dl className="mt-2 flex gap-6 text-sm">
                  <div><dt className="inline font-medium">Status</dt> <dd className="inline" data-testid="sample-status">{sample.status}</dd></div>
                  <div><dt className="inline font-medium">Latency</dt> <dd className="inline" data-testid="sample-latency">{sample.latency_ms} ms</dd></div>
                </dl>
              </CardHeader>
              <CardContent><pre className="max-h-[32rem] overflow-auto whitespace-pre-wrap text-xs" data-testid="sample-json">{renderedJson}</pre></CardContent>
            </Card>
            <JsonPathPicker onPick={insertPath} paths={sample.paths} />
          </section>
        ) : null}
      </div>
    </main>
  );
}
