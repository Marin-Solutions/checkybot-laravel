import React, { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import type { SamplePath } from './api-builder-contracts';
import { Button, Card, CardContent, CardHeader } from './ui';

export function JsonPathPicker({
  paths,
  onPick,
}: {
  paths: SamplePath[];
  onPick: (path: string) => void;
}) {
  const [activeIndex, setActiveIndex] = useState(0);
  const buttons = useRef<Array<HTMLButtonElement | null>>([]);

  useEffect(() => {
    setActiveIndex((current) => Math.min(current, Math.max(0, paths.length - 1)));
  }, [paths.length]);

  const move = (next: number) => {
    const bounded = Math.max(0, Math.min(paths.length - 1, next));
    setActiveIndex(bounded);
    buttons.current[bounded]?.focus();
  };

  const handleKey = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
      event.preventDefault();
      move(index + 1);
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
      event.preventDefault();
      move(index - 1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      move(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      move(paths.length - 1);
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      onPick(paths[index].path);
    }
  };

  return (
    <Card data-testid="json-path-picker">
      <CardHeader>
        <h2 className="font-semibold">Selectable JSON paths</h2>
        <p className="mt-1 text-sm text-slate-600" id="path-picker-help">
          Use arrow keys to move through paths, then press Enter to insert one.
        </p>
      </CardHeader>
      <CardContent>
        {paths.length === 0 ? <p>No selectable paths were returned.</p> : (
          <div aria-describedby="path-picker-help" aria-label="Returned JSON paths" role="tree">
            {paths.map((item, index) => (
              <Button
                aria-label={`${item.path}, ${item.inferred_type}, ${item.preview}`}
                aria-selected={index === activeIndex}
                className="mb-2 flex w-full justify-start gap-3 font-mono"
                data-path={item.path}
                key={item.path}
                onClick={() => onPick(item.path)}
                onFocus={() => setActiveIndex(index)}
                onKeyDown={(event) => handleKey(event, index)}
                ref={(node) => { buttons.current[index] = node; }}
                role="treeitem"
                tabIndex={index === activeIndex ? 0 : -1}
              >
                <span>{item.path}</span>
                <span className="font-sans text-xs text-slate-500">{item.inferred_type} · {item.preview}</span>
              </Button>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
