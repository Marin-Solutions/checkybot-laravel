import React from 'react';
import type { ApiAssertion, AssertionDraft, AssertionKind, AssertionOperator } from './api-builder-contracts';
import { Button, Card, CardContent, CardHeader } from './ui';

const operators: Record<AssertionKind, AssertionOperator[]> = {
  status: ['equals', 'in'],
  latency: ['less_than_or_equal'],
  json_path: ['exists', 'not_null', 'equals', 'not_equals', 'type', 'non_empty'],
};

function initialAssertion(kind: AssertionKind, id: string): AssertionDraft {
  if (kind === 'latency') return { id, kind, operator: 'less_than_or_equal', expected: 1000 };
  if (kind === 'json_path') return { id, kind, operator: 'exists', path: '$' };
  return { id, kind, operator: 'equals', expected: 200 };
}

export function assertionPayload(assertion: AssertionDraft): ApiAssertion {
  const result: ApiAssertion = { kind: assertion.kind, operator: assertion.operator };
  if (assertion.kind === 'json_path') result.path = assertion.path ?? '$';
  if (!['exists', 'not_null', 'non_empty'].includes(assertion.operator)) result.expected = assertion.expected;
  return result;
}

function expectedText(assertion: AssertionDraft): string {
  if (assertion.operator === 'in' && Array.isArray(assertion.expected)) return assertion.expected.join(', ');
  if (assertion.expected === null) return 'null';
  if (typeof assertion.expected === 'string') return assertion.expected;
  return assertion.expected === undefined ? '' : String(assertion.expected);
}

function parseExpected(assertion: AssertionDraft, value: string): unknown {
  if (assertion.kind === 'status' && assertion.operator === 'in') {
    return value.split(',').map((item) => Number(item.trim())).filter(Number.isFinite);
  }
  if (assertion.kind === 'status' || assertion.kind === 'latency') return Number(value);
  if (assertion.operator === 'type') return value;
  if (value === 'null') return null;
  if (value === 'true') return true;
  if (value === 'false') return false;
  if (value !== '' && Number.isFinite(Number(value))) return Number(value);
  return value;
}

function acceptsExpected(operator: AssertionOperator): boolean {
  return !['exists', 'not_null', 'non_empty'].includes(operator);
}

export function AssertionEditor({
  assertions,
  errors,
  onChange,
  onChoosePath,
}: {
  assertions: AssertionDraft[];
  errors: Record<string, string[]>;
  onChange: (assertions: AssertionDraft[]) => void;
  onChoosePath: (index: number) => void;
}) {
  const patch = (index: number, update: Partial<AssertionDraft>) => {
    onChange(assertions.map((assertion, row) => row === index ? { ...assertion, ...update } : assertion));
  };
  const move = (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= assertions.length) return;
    const next = [...assertions];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  };

  return (
    <Card>
      <CardHeader>
        <h2 className="font-semibold">Ordered assertions</h2>
        <p className="mt-1 text-sm text-slate-600">Assertions run from top to bottom.</p>
      </CardHeader>
      <CardContent className="space-y-4">
        {assertions.map((assertion, index) => {
          const rowErrors = Object.entries(errors)
            .filter(([key]) => key === `assertions.${index}` || key.startsWith(`assertions.${index}.`))
            .flatMap(([, messages]) => messages);
          return (
            <fieldset className="rounded-lg border border-slate-200 p-3" data-assertion-row={index} key={assertion.id}>
              <legend className="px-1 text-sm font-semibold">Assertion {index + 1}</legend>
              <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm">
                  Kind
                  <select
                    aria-label={`Assertion ${index + 1} kind`}
                    className="mt-1 block min-h-10 w-full rounded-md border px-3"
                    onChange={(event) => {
                      const next = initialAssertion(event.currentTarget.value as AssertionKind, assertion.id);
                      onChange(assertions.map((item, row) => row === index ? next : item));
                    }}
                    value={assertion.kind}
                  >
                    <option value="status">HTTP status</option>
                    <option value="latency">Latency</option>
                    <option value="json_path">JSON path</option>
                  </select>
                </label>
                <label className="text-sm">
                  Operator
                  <select
                    aria-label={`Assertion ${index + 1} operator`}
                    className="mt-1 block min-h-10 w-full rounded-md border px-3"
                    onChange={(event) => {
                      const operator = event.currentTarget.value as AssertionOperator;
                      const expected = acceptsExpected(operator)
                        ? (operator === 'type' ? 'string' : assertion.expected ?? '')
                        : undefined;
                      patch(index, { operator, expected });
                    }}
                    value={assertion.operator}
                  >
                    {operators[assertion.kind].map((operator) => <option key={operator} value={operator}>{operator}</option>)}
                  </select>
                </label>
              </div>
              {assertion.kind === 'json_path' ? (
                <div className="mt-3 flex items-end gap-2">
                  <label className="flex-1 text-sm">
                    Manual JSON path
                    <input
                      aria-label={`Assertion ${index + 1} manual JSON path`}
                      className="mt-1 block min-h-10 w-full rounded-md border px-3 font-mono"
                      onChange={(event) => patch(index, { path: event.currentTarget.value })}
                      value={assertion.path ?? ''}
                    />
                  </label>
                  <Button onClick={() => onChoosePath(index)}>Choose from sample</Button>
                </div>
              ) : null}
              {acceptsExpected(assertion.operator) ? (
                <label className="mt-3 block text-sm">
                  Expected value{assertion.operator === 'in' ? ' (comma separated)' : ''}
                  <input
                    aria-label={`Assertion ${index + 1} expected value`}
                    className="mt-1 block min-h-10 w-full rounded-md border px-3"
                    onChange={(event) => patch(index, { expected: parseExpected(assertion, event.currentTarget.value) })}
                    value={expectedText(assertion)}
                  />
                </label>
              ) : null}
              {rowErrors.length > 0 ? (
                <ul className="mt-2 text-sm text-rose-700" data-testid={`assertion-errors-${index}`} role="alert">
                  {rowErrors.map((message, errorIndex) => <li key={`${message}-${errorIndex}`}>{message}</li>)}
                </ul>
              ) : null}
              <div className="mt-3 flex flex-wrap gap-2">
                <Button aria-label={`Move assertion ${index + 1} up`} disabled={index === 0} onClick={() => move(index, -1)}>Move up</Button>
                <Button aria-label={`Move assertion ${index + 1} down`} disabled={index === assertions.length - 1} onClick={() => move(index, 1)}>Move down</Button>
                <Button aria-label={`Remove assertion ${index + 1}`} onClick={() => onChange(assertions.filter((_, row) => row !== index))}>Remove</Button>
              </div>
            </fieldset>
          );
        })}
        <div className="flex flex-wrap gap-2">
          {(['status', 'latency', 'json_path'] as const).map((kind) => (
            <Button key={kind} onClick={() => onChange([...assertions, initialAssertion(kind, `assertion-${Date.now()}-${assertions.length}`)])}>
              Add {kind === 'json_path' ? 'JSON path' : kind} assertion
            </Button>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
