# Canonical integration verification proof

- Run ID: `{{RUN_ID}}`
- Commit: `{{COMMIT}}`
- Started: `{{STARTED_AT}}`
- Completed: `{{COMPLETED_AT}}`
- Result: **{{RESULT}}**

## Commands

{{COMMANDS}}

## Runtime endpoints

- Laravel readiness: `{{BACKEND_URL}}/__harness/ready`
- Queue submission: `{{BACKEND_URL}}/__harness/queue-probes`
- Queue result: `{{BACKEND_URL}}/__harness/queue-probes/{{PROBE_ID}}`
- Expo web fixture: `{{FRONTEND_URL}}`

## Queue probe evidence

- Probe ID: `{{PROBE_ID}}`
- Accepted at: `{{ACCEPTED_AT}}`
- Processed at: `{{PROCESSED_AT}}`
- POST status: `{{POST_STATUS}}`
- GET status: `{{GET_STATUS}}`

## Result summary

{{RESULT_SUMMARY}}

## Evidence paths

- Integration proof: `{{PROOF_PATH}}`
- Backend runtime log: `{{RUNTIME_LOG}}`
- Laravel app log: `{{APP_LOG}}`
- Queue worker log: `{{WORKER_LOG}}`
- Expo fixture log: `{{FIXTURE_LOG}}`
- Component test log: `{{COMPONENT_LOG}}`
- Playwright log: `{{PLAYWRIGHT_LOG}}`
- Queue probe JSON: `{{PROBE_EVIDENCE}}`
- Processed-state screenshot: `{{SCREENSHOT}}`
- Playwright trace directory: `{{TRACE_PATH}}`
