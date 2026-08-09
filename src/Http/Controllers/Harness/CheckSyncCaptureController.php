<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\Harness;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\ContractValidator;
use RuntimeException;

/**
 * Harness-only receiver for proving the SDK's real outbound sync transport.
 *
 * This controller is registered only in testing/harness environments and is
 * protected by the loopback middleware. Production never exposes this route.
 */
final readonly class CheckSyncCaptureController
{
    public function __construct(private ContractValidator $contract) {}

    public function __invoke(Request $request, string $projectId): JsonResponse
    {
        $configuredKey = (string) config('checkybot-laravel.api_key');
        $presentedKey = $request->bearerToken();

        if ($configuredKey === '' || ! is_string($presentedKey) || ! hash_equals($configuredKey, $presentedKey)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! hash_equals((string) config('checkybot-laravel.project_id'), $projectId)) {
            return response()->json(['message' => 'The API key cannot manage this project.'], 403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        foreach (['uptime', 'ssl', 'api', 'dead_links', 'open_graph', 'domain_expiry', 'response_time_budget'] as $type) {
            if (! array_key_exists($type, $payload)) {
                ContractValidator::fail($type, "The {$type} field is required.");
            }
        }
        $this->contract->validateCheckSync($payload);
        $operationId = (string) Str::uuid();

        $runDirectory = (string) getenv('HARNESS_RUN_DIR');
        if ($runDirectory === '' || ! is_dir($runDirectory)) {
            throw new RuntimeException('The harness sync capture directory is unavailable.');
        }

        $capture = [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'accept' => $request->header('Accept'),
            'content_type' => $request->header('Content-Type'),
            'authenticated' => true,
            'project_id' => $projectId,
            'body' => $payload,
        ];

        file_put_contents(
            $runDirectory.'/sdk-sync-capture.json',
            json_encode($capture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            LOCK_EX,
        );

        return response()->json([
            'status' => 'queued',
            'event_type' => 'contract.check_sync.probed',
            'operation_id' => $operationId,
        ], 202);
    }
}
