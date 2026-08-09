# External watchdog and push-fallback retirement

Telegram is not a separate Checkybot channel in v1. The **Telegram fallback maps to the existing generic legacy webhook** configured by the deployment. Every critical incident and critical recovery remains dual-sent to Expo and that generic webhook throughout proving. Removing, disabling, or repointing the webhook is retirement and is forbidden until this checklist is signable.

## Independence gate

The watchdog provider and probe must run outside the Checkybot host and failure domain. Its check must not call a Checkybot URL, read Checkybot's database, consume Checkybot's queue, or depend on Checkybot to schedule the probe. The external provider calls the secret HTTPS heartbeat URL while Checkybot independently performs its every-minute outbound `GET` with overlap prevention.

Exercise the configured outbound client without printing the URL:

```bash
php artisan checkybot:watchdog
printf 'exit_code=%s responsible_owner=%s recorded_at=%s\n' "$?" "$INFRA_OWNER" "$(date -u +%FT%TZ)"
```

Command exit alone is not proof: disabled configuration and transport/HTTP failure intentionally do not stop future schedules. Obtain the provider-side redacted receipt and write only status, HTTP class, provider event identifier, `checked_at`, `independent=true`, `depends_on_checkybot=false`, owner, and evidence path to `evidence/<run-id>/watchdog.json`.

**Pass:** provider receipt is a 2xx success, independently observed, and at signing is no more than 5 minutes old. **Fail:** missing configuration/proof, non-2xx, timeout, transport error, a future timestamp, age greater than 5 minutes, or any dependency on Checkybot. A failed minute does not block later scheduler attempts, but it blocks handover until a new current success exists.

## 28-day proving gate

Query the authorized project read model without printing tokens, webhook configuration, device tokens, or payloads:

```bash
php artisan tinker --execute="dump(app(\\MarinSolutions\\CheckybotLaravel\\Domain\\Push\\Queries\\PushReliabilityReadModel::class)->forProject(new \\MarinSolutions\\CheckybotLaravel\\Domain\\Push\\Contracts\\ProjectIdentity('$CHECKYBOT_PROJECT_UUID')));"
```

Save the redacted counters to `evidence/<run-id>/push-reliability.json` with `responsible_owner` and `recorded_at`. The fixed window contains the trailing **28 complete elapsed UTC calendar days**; the current UTC day is never counted.

The gate passes only when all are true:

- `retirement_ready=true` and `consecutive_complete_days=28`;
- every day has at least one required critical intent;
- aggregate `critical_intents` is positive;
- `expo_accepted` equals `critical_intents`;
- `legacy_webhook_accepted` equals `critical_intents`;
- `failed_or_missing_pairs=0`; and
- the independent watchdog gate above is currently passing.

Fewer than 28 consecutive complete days, any failed or missing Expo/webhook receipt, or any new failure resets/blocks signing. Historical success never overrides a current watchdog failure. Use `.full-send/canvas-runs/current/handover-checklists/v1/push-retirement.template.json`; an operator cannot mark it signed merely by setting a checkbox.

## Retirement procedure

1. Freeze delivery configuration and record commit, release owner, UTC timestamp, evidence paths, and read-model output.
2. Run `./vendor/bin/pest tests/Feature/ReleaseHardening --compact` and require exit 0.
3. Re-fetch the independent provider receipt and require age ≤300 seconds.
4. Re-query `PushReliabilityReadModel` and require the exact thresholds above.
5. Run the evidence secret scan from `docs/release-hardening.md`; require 0 matches.
6. Have the infrastructure owner and release manager sign `push-retirement.v1` with UTC timestamps.
7. Disable only the generic legacy-webhook/Telegram mapping. Do not change Expo delivery or the external watchdog.
8. Observe one release window and preserve status, queue, provider, and heartbeat evidence.

## Rollback and expiry

Rollback immediately if Expo is failed/missing, queue processing stalls, an incident is not delivered, the watchdog becomes stale or failed, or the secret scan fails. Restore the generic webhook configuration through the approved secret channel, send a controlled critical test, and require one deduplicated accepted Expo receipt plus one accepted legacy-webhook receipt sharing the incident thread. Record rollback owner, trigger timestamp, completion timestamp, and restoration evidence path.

The watchdog proof expires after 5 minutes. The retirement decision expires immediately on a new failed/missing critical pair, a watchdog failure, or delivery configuration change. Pest evidence expires after 24 hours or at the next commit, whichever comes first. If any artifact expires before signing, regenerate it; never extend timestamps manually.
