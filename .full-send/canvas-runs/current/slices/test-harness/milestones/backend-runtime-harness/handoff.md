# Laravel runtime and queue lifecycle harness — handoff

Outcome: completed

Implemented the backend-only canonical harness lifecycle within the slice ownership.

## Runtime entry points

```bash
scripts/runtime/backend start
scripts/runtime/backend stop --run-dir <path emitted by start>
composer harness:test:backend
```

Start emits `HARNESS_RUN_ID`, `HARNESS_RUN_DIR`, and `HARNESS_BACKEND_URL`. Runtime metadata is also written to `<run-dir>/runtime.json`. The frontend integration milestone can consume these values and the three contracted endpoints directly.

## Delivered behavior

- Harness-only Laravel development server bound to `127.0.0.1`.
- Real Laravel database queue worker (`queue:work`, not sync).
- Run-local SQLite queue/probe state, bootstrap cache, storage, logs, status, and PID records.
- Ownership-checked stop and failure cleanup that signal only recorded children from the same run.
- Real readiness, queue submission, and queue status APIs.
- Failure diagnostics for occupied port, readiness timeout, and premature worker exit.
- Refusal of non-SQLite and non-run-scoped database configuration.

## Verification

- Targeted harness suite: 6 passed, 49 assertions (two consecutive rounds).
- Full Pest suite: 221 passed, 537 assertions.
- Pint check: passed.
- No harness-owned process remained after verification.

Detailed acceptance evidence is in `ledger.md`. No migrations or destructive database commands were run.
