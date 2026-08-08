# Release hardening and production handover

This is the executable v1 release gate. It is fail-closed: a missing, stale, unsigned, or unredacted artifact is a failed gate, never a warning. The release manager records `responsible_owner`, `recorded_at`, and the command exit code for every item under `.full-send/canvas-runs/current/handover-checklists/evidence/<run-id>/`.

## Gate matrix

| Gate | Pass threshold | Evidence path | Owner | Recency / expiry | Rollback trigger |
|---|---|---|---|---|---|
| False-alarm runtime | A failed pull followed by success in **less than 30 seconds** has 0 incident/recovery intents, 0 Expo deliveries, and 0 legacy-webhook deliveries. | `evidence/<run-id>/release-gate-pest.txt` | Backend release owner | Same commit; expires after 24 hours | Any intent or delivery for the blip |
| Grouped incident | Exactly 1 grouped incident and 1 grouped recovery share one thread key; each critical intent has exactly 1 accepted Expo delivery and 1 accepted generic legacy-webhook delivery. | `evidence/<run-id>/release-gate-pest.txt` | Backend release owner | Same commit; expires after 24 hours | Duplicate, missing, failed, or cross-thread delivery |
| Status freshness | `updated_at=null`, server `stale=true`, local age greater than 900 seconds, 401/403, and transport failure never render healthy green. | `evidence/<run-id>/status-surface/` | Mobile/web owner | Same commit; expires after 24 hours | Stale/error data rendered healthy |
| External watchdog | Last independent heartbeat is HTTP 2xx and no more than 5 minutes old. | `evidence/<run-id>/watchdog.json` | Infrastructure owner | Expires after 5 minutes | Missing/non-2xx/stale/dependent heartbeat |
| Push fallback retirement | 28 consecutive complete elapsed UTC days, at least one critical intent per day, no failed/missing pair, and accepted Expo + generic webhook counts equal critical intents. | `evidence/<run-id>/push-reliability.json` | Release manager | Re-evaluate at signing; expires on any new failed/missing pair | Any incomplete day or delivery pair |
| Fleet readiness | Every enabled server satisfies `fleet-readiness.v1`. | `evidence/<run-id>/fleet-readiness.json` | Fleet rollout owner | Latest report no older than 120 seconds; inventory expires after 24 hours | Missing heartbeat, rejected report, prerequisite/cap/version drift, redaction failure |
| Secret scan | 0 plaintext tokens, provider credentials, webhook URLs, authorization values, or unredacted payloads. | `evidence/<run-id>/secret-scan.txt` | Security owner | Run last, immediately before signing | Any match |

## Canonical commands

Run from the repository root. Capture output and exit code without changing the commands.

```bash
mkdir -p ".full-send/canvas-runs/current/handover-checklists/evidence/${RUN_ID}"
./vendor/bin/pest tests/Feature/ReleaseHardening --compact | tee ".full-send/canvas-runs/current/handover-checklists/evidence/${RUN_ID}/release-gate-pest.txt"
printf 'exit_code=%s\nresponsible_owner=%s\nrecorded_at=%s\n' "${PIPESTATUS[0]}" "${RELEASE_OWNER}" "$(date -u +%FT%TZ)" >> ".full-send/canvas-runs/current/handover-checklists/evidence/${RUN_ID}/release-gate-pest.txt"
```

Pass only when Pest exits 0 and all five `AC-release-hardening-*` groups pass. The runtime test starts a run-scoped SQLite backend and a real database queue worker, posts to `/__harness/alerting/results`, invokes the registered foundation relay, and reads the alerting and push receipt APIs.

Record the deploy identity:

```bash
git rev-parse HEAD > ".full-send/canvas-runs/current/handover-checklists/evidence/${RUN_ID}/commit.txt"
date -u +%FT%TZ > ".full-send/canvas-runs/current/handover-checklists/evidence/${RUN_ID}/completed-at.txt"
```

## Fleet rollout ordering

Use `docs/agent-v2-rollout.md` for host commands. Inventory all enabled hosts before rollout. Roll out one non-critical `variant-canary` per OS/PHP-FPM variant, then **5% → 25% → 50% → 100%**. Do not advance a wave until every host in the previous wave has:

1. an accepted `agent-report.v2` at exactly 60 seconds using the expected semantic version;
2. readable nginx access/error and PHP-FPM prerequisites;
3. readable MySQL, or an optional-MySQL exception with approver, reason, and timestamp;
4. confirmed positive link cap;
5. a passed canary-wave result and evidence path;
6. a rotated token scoped to only `agent:report`, a new-token accepted report, and old-token revocation proof; and
7. a rollback owner plus the procedure and evidence path for a fresh post-rollback heartbeat within 120 seconds.

Use `.full-send/canvas-runs/current/handover-checklists/v1/fleet-readiness.template.json`. Compare enabled and inventoried UUID sets; a count-only comparison is insufficient.

## Rollback

Trigger rollback for any unexpected notification, duplicate delivery, rejected report, missing heartbeat, stale healthy UI, prerequisite/cap/version drift, provider failure, or secret-scan match. The responsible owner timestamps the trigger and result. Preserve evidence and monitoring state; never delete reports to make a gate pass. Restore the pinned prior agent/config, restore a still-active old scoped token if required, and prove a fresh accepted heartbeat before closing rollback.

## Evidence redaction gate

Run this last against the evidence directory. It deliberately rejects likely credentials, authorization headers, Expo tokens, webhook URLs with query credentials, and raw payload fields. A match is failure; redact at the source and regenerate the artifact rather than editing audit evidence in place.

```bash
EVIDENCE_DIR=".full-send/canvas-runs/current/handover-checklists/evidence/${RUN_ID}"
if grep -RInE '(Authorization:[[:space:]]*Bearer|plaintext[_-]?token|provider[_-]?credentials?|expo(push)?token|webhook[_-]?url|raw[_-]?payload|https://[^[:space:]]+\?[^[:space:]]+)' "$EVIDENCE_DIR" > "$EVIDENCE_DIR/secret-scan.txt"; then
  printf '%s\n' 'FAIL: forbidden secret or unredacted payload marker found' >> "$EVIDENCE_DIR/secret-scan.txt"
  exit 1
fi
printf 'PASS responsible_owner=%s recorded_at=%s matches=0\n' "$SECURITY_OWNER" "$(date -u +%FT%TZ)" > "$EVIDENCE_DIR/secret-scan.txt"
```

Signing requires completed `release-evidence.v1`, `push-retirement.v1`, and `fleet-readiness.v1` manifests, all evidence paths present, all timestamps within their expiry windows, zero secret-scan matches, and named owners. An empty or placeholder field fails.
