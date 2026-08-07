import { expect, test } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const evidencePath = resolve(process.env.HARNESS_PROBE_EVIDENCE || 'build/playwright-results/probe-evidence.json');
const screenshotPath = resolve(process.env.HARNESS_SCREENSHOT || 'build/playwright-results/processed.png');

test('Expo fixture proves readiness and real queue processing', async ({ page }) => {
  const apiResponses: Array<{ method: string; url: string; status: number; body: unknown }> = [];

  page.on('response', async (response) => {
    if (!response.url().includes('/__harness/')) return;
    try {
      apiResponses.push({
        method: response.request().method(),
        url: response.url(),
        status: response.status(),
        body: await response.json(),
      });
    } catch {
      // A failed/non-JSON response is still surfaced by the fixture and test assertions.
    }
  });

  await page.goto('/');
  await expect(page.getByTestId('backend-ready')).toContainText('Backend ready');
  const runId = (await page.getByText(/^Run ID:/).textContent())?.replace('Run ID: ', '') || '';

  const acceptedResponsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/__harness/queue-probes',
  );
  await page.getByRole('button', { name: 'Create queue probe' }).click();
  const acceptedResponse = await acceptedResponsePromise;
  expect(acceptedResponse.status()).toBe(202);
  const accepted = await acceptedResponse.json() as {
    status: string;
    probe_id: string;
    accepted_at: string;
  };

  await expect(page.getByTestId('queue-queued')).toContainText('Queue probe queued');
  await expect(page.getByTestId('queue-processed')).toContainText('Queue probe processed');
  await expect(page.getByTestId('queue-processed')).toContainText(accepted.probe_id);
  await page.screenshot({ path: screenshotPath, fullPage: true });

  await expect.poll(() => apiResponses.some(({ method, body }) =>
    method === 'GET'
      && typeof body === 'object'
      && body !== null
      && (body as { probe_id?: string }).probe_id === accepted.probe_id
      && (body as { status?: string }).status === 'processed',
  )).toBe(true);
  const processedResponse = [...apiResponses].reverse().find(({ method, body }) =>
    method === 'GET'
      && typeof body === 'object'
      && body !== null
      && (body as { probe_id?: string }).probe_id === accepted.probe_id
      && (body as { status?: string }).status === 'processed',
  );

  await mkdir(dirname(evidencePath), { recursive: true });
  await writeFile(evidencePath, `${JSON.stringify({
    run_id: runId,
    frontend_url: page.url(),
    probe_id: accepted.probe_id,
    accepted_at: accepted.accepted_at,
    processed_at: (processedResponse?.body as { processed_at: string }).processed_at,
    post_status: acceptedResponse.status(),
    get_status: processedResponse?.status,
    api_responses: apiResponses,
    screenshot: screenshotPath,
  }, null, 2)}\n`);
});
