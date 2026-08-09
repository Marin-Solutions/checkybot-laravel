import React from 'react';
import type { HeaderDraft } from './api-builder-contracts';
import { Button, Card, CardContent, CardHeader } from './ui';

export function HeaderEditor({
  headers,
  onChange,
}: {
  headers: HeaderDraft[];
  onChange: (headers: HeaderDraft[]) => void;
}) {
  const patch = (index: number, update: Partial<HeaderDraft>) => {
    onChange(headers.map((header, row) => row === index ? { ...header, ...update } : header));
  };

  return (
    <Card>
      <CardHeader>
        <h2 className="font-semibold">Request headers</h2>
        <p className="mt-1 text-sm text-slate-600">Stored values stay masked. Choose Replace before entering a new secret.</p>
      </CardHeader>
      <CardContent className="space-y-3">
        {headers.map((header, index) => (
          <fieldset className="rounded-lg border border-slate-200 p-3" data-header-row={index} key={header.id}>
            <legend className="px-1 text-sm font-medium">Header {index + 1}</legend>
            <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
              <label className="text-sm">
                Name
                <input
                  aria-label={`Header ${index + 1} name`}
                  className="mt-1 block min-h-10 w-full rounded-md border px-3"
                  onChange={(event) => patch(index, { name: event.currentTarget.value })}
                  value={header.name}
                />
              </label>
              <div className="text-sm">
                <span className="block">Stored value</span>
                <output aria-label={`Header ${index + 1} stored value`} className="mt-1 flex min-h-10 items-center font-mono" data-testid={`header-mask-${index}`}>
                  {header.hasValue ? header.mask : 'No stored value'}
                </output>
              </div>
              <div className="flex items-end gap-2">
                {header.hasValue && header.action !== 'set' ? (
                  <Button onClick={() => patch(index, { action: 'set', replacement: '' })}>Replace</Button>
                ) : null}
                {header.action !== 'remove' ? (
                  <Button onClick={() => patch(index, { action: 'remove', replacement: '' })}>Remove</Button>
                ) : (
                  <Button onClick={() => patch(index, { action: header.hasValue ? 'preserve' : 'set' })}>Undo removal</Button>
                )}
              </div>
            </div>
            {header.action === 'set' ? (
              <label className="mt-3 block text-sm">
                New value
                <input
                  aria-label={`Header ${index + 1} new value`}
                  autoComplete="new-password"
                  className="mt-1 block min-h-10 w-full rounded-md border px-3"
                  onChange={(event) => patch(index, { replacement: event.currentTarget.value })}
                  type="password"
                  value={header.replacement}
                />
              </label>
            ) : null}
            <p className="mt-2 text-xs text-slate-600" data-testid={`header-action-${index}`}>
              Save action: {header.action}
            </p>
          </fieldset>
        ))}
        <Button onClick={() => onChange([...headers, {
          id: `header-${Date.now()}-${headers.length}`,
          name: '',
          mask: '',
          hasValue: false,
          action: 'set',
          replacement: '',
        }])}>Add header</Button>
      </CardContent>
    </Card>
  );
}
