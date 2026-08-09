# Changelog

All notable changes to `checkybot-laravel` will be documented in this file.

## Unreleased

- Sends the canonical `check-sync.v1` body with uptime, SSL, API, dead-link, OpenGraph, domain-expiry, and response-time-budget arrays.
- Adds domain-expiry declarations (30-day default), p95 response-time budgets (2000 ms default), and ordered API status, latency, and JSON body assertion metadata to fluent and config APIs.
- Keeps existing fluent methods, config names, and legacy sync-summary aliases compatible while servers roll out `check-sync.v1`; deploy the compatible server before upgrading the package.
- Sends header and token plaintext only to the authenticated HTTPS API for downstream encryption at rest and masks those values from package output, exceptions, logs, debugging, and integration evidence.

## v0.2.1 - 2026-07-31

Hardens runtime component status reporting for retries and clock skew.

- Sends a stable `Idempotency-Key` per report and reuses it across configured retries; callers may provide a key for safe manual retries.
- Documents and tests the server's 120-second future timestamp tolerance.
- Keeps declaration sync and the v0.2.0 request body unchanged.
- The compatible Checkybot server hardening is commit `1e43daf3334cef0e49dd409b317a650bb74d9984` in `marin-solutions/checkybot`.

## v0.2.0 - 2026-07-31

Adds the supported runtime component status contract for already-declared aggregate components.

- Adds authenticated `CheckybotClient::reportComponentStatus()` with RFC3339 UTC normalization, allowlisted bounded metrics, input validation, configured timeout, and retry handling.
- Keeps declaration sync separate and backward-compatible; runtime fields are never added to sync payloads.
- Documents the coordinated Checkybot endpoint: `POST /api/v1/projects/{projectId}/components/{componentKey}/status`.
- The compatible Checkybot server implementation is commit `0ee9aa61a6ec2ddcd4396b0d40ce9752db53110e` in `marin-solutions/checkybot`.
- Consumers should upgrade with `composer require marin-solutions/checkybot-laravel:^0.2.0` only after the compatible Checkybot server endpoint and observation migration are deployed.

## v0.1.1 - 2026-07-21

Adds expected HTTP status and per-check retry configuration for API monitors.
