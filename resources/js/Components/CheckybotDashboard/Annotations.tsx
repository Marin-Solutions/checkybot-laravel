import React from 'react';
import type { AnnotationSlot } from './contracts';
import { humanize } from './format';
import { Card, CardContent } from './ui';

export function Annotations({ slots }: { slots: AnnotationSlot[] }) {
  return (
    <dl className="grid gap-3 sm:grid-cols-2" data-testid="annotation-slots">
      {slots.map((slot) => (
        <Card key={slot.key}>
          <CardContent>
            <dt className="text-sm font-semibold text-slate-900">{humanize(slot.key)}</dt>
            <dd className={slot.value === null ? 'mt-1 text-sm italic text-slate-500' : 'mt-1 whitespace-pre-wrap text-sm text-slate-800'}>
              {slot.value === null ? <span aria-label={`${humanize(slot.key)} unavailable`}>Unavailable</span> : slot.value}
            </dd>
          </CardContent>
        </Card>
      ))}
    </dl>
  );
}
