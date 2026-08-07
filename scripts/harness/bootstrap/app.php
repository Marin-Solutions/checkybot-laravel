<?php

declare(strict_types=1);

use Checkybot\Harness\HarnessAccess;
use Checkybot\Harness\ProcessQueueProbe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\CheckybotLaravelServiceProvider;

require_once __DIR__.'/autoload.php';

$root = dirname(__DIR__, 3);
putenv('COMPOSER_VENDOR_DIR='.$root.'/vendor');

$app = Application::configure(basePath: dirname(__DIR__))
    ->withProviders([CheckybotLaravelServiceProvider::class])
    ->withRouting(using: static function (): void {
        if (! HarnessAccess::routesAreEnabled()) {
            return;
        }

        Route::middleware('api')->group(static function (): void {
            Route::get('/__harness/ready', static function (Request $request) {
                abort_unless(HarnessAccess::requestIsLoopback($request), 404);

                return response()->json([
                    'app' => 'ready',
                    'queue' => HarnessAccess::workerIsReady() ? 'ready' : 'starting',
                    'run_id' => (string) getenv('HARNESS_RUN_ID'),
                ]);
            });

            Route::post('/__harness/queue-probes', static function (Request $request) {
                abort_unless(HarnessAccess::requestIsLoopback($request), 404);

                $validator = Validator::make($request->all(), [
                    'probe_id' => ['required', 'uuid', 'unique:harness_queue_probes,probe_id'],
                ]);

                $validated = $validator->validate();
                $acceptedAt = now()->toISOString();

                try {
                    DB::table('harness_queue_probes')->insert([
                        'probe_id' => $validated['probe_id'],
                        'status' => 'queued',
                        'accepted_at' => $acceptedAt,
                        'processed_at' => null,
                    ]);
                } catch (QueryException) {
                    throw ValidationException::withMessages([
                        'probe_id' => ['The probe id has already been taken.'],
                    ]);
                }

                ProcessQueueProbe::dispatch($validated['probe_id']);

                return response()->json([
                    'status' => 'queued',
                    'probe_id' => $validated['probe_id'],
                    'accepted_at' => $acceptedAt,
                ], 202);
            });

            Route::get('/__harness/queue-probes/{probe_id}', static function (Request $request, string $probeId) {
                abort_unless(HarnessAccess::requestIsLoopback($request), 404);

                $probe = DB::table('harness_queue_probes')->where('probe_id', $probeId)->first();

                if ($probe === null) {
                    return response()->json(['message' => 'Queue probe not found'], 404);
                }

                return response()->json([
                    'status' => $probe->status,
                    'probe_id' => $probe->probe_id,
                    'processed_at' => $probe->processed_at,
                ]);
            });
        });
    })
    ->withMiddleware(static function (Middleware $middleware): void {})
    ->withExceptions(static function (Exceptions $exceptions): void {})
    ->create();

$runDirectory = (string) getenv('HARNESS_RUN_DIR');

if ($runDirectory !== '') {
    $app->useBootstrapPath($runDirectory.'/bootstrap');
    $app->useStoragePath($runDirectory.'/storage');
    $app->useDatabasePath($runDirectory);
}

return $app;
