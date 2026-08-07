<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/push-device-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->pushDeviceDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->pushDeviceDatabase);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => $this->pushDeviceDatabase, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000]);
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_020000_create_push_delivery_tables.php')->up();
    $this->project = (string) Str::uuid();
    $this->user = new GenericUser(['id' => 'mobile-user-1', 'project_ids' => [$this->project]]);
    $this->validDevice = [
        'installation_id' => (string) Str::uuid(),
        'expo_push_token' => 'ExponentPushToken[token_one-123]',
        'platform' => 'ios',
        'project_uuid' => $this->project,
        'permission' => 'granted',
        'app_version' => '1.2.3+42',
    ];
});

afterEach(function (): void {
    DB::disconnect('sqlite');
    @unlink($this->pushDeviceDatabase);
});

it('registers, rotates and idempotently deactivates one authorized installation', function (): void {
    $created = $this->actingAs($this->user)->postJson('/api/v1/push-devices', $this->validDevice)
        ->assertCreated()->assertJsonPath('data.active', true)->assertJsonPath('data.project_uuid', $this->project);
    $deviceId = $created->json('data.id');

    $rotated = [...$this->validDevice, 'expo_push_token' => 'ExpoPushToken[rotated_456]', 'permission' => 'provisional'];
    $this->postJson('/api/v1/push-devices', $rotated)->assertOk()->assertJsonPath('data.id', $deviceId);
    expect(PushDevice::query()->count())->toBe(1)
        ->and(PushDevice::query()->sole()->expo_push_token)->toBe('ExpoPushToken[rotated_456]');

    $this->deleteJson("/api/v1/push-devices/{$deviceId}")->assertNoContent();
    $this->deleteJson("/api/v1/push-devices/{$deviceId}")->assertNoContent();
    expect(PushDevice::query()->where('active', true)->count())->toBe(0);
})->group('AC-push-mobile-widget-status-1');

it('enforces authentication project scope private ownership and validation', function (): void {
    $this->postJson('/api/v1/push-devices', $this->validDevice)->assertUnauthorized();
    $this->actingAs($this->user);
    $this->postJson('/api/v1/push-devices', [...$this->validDevice, 'project_uuid' => (string) Str::uuid()])->assertForbidden();

    foreach ([
        ['installation_id' => 'bad'],
        ['project_uuid' => 'bad'],
        ['platform' => 'web'],
        ['permission' => 'denied'],
        ['expo_push_token' => 'secret-token'],
        ['app_version' => str_repeat('x', 65)],
        ['app_version' => 'not a version'],
    ] as $invalid) {
        $this->postJson('/api/v1/push-devices', [...$this->validDevice, ...$invalid])->assertUnprocessable()->assertJsonStructure(['message', 'errors']);
    }

    $device = $this->postJson('/api/v1/push-devices', $this->validDevice)->assertCreated()->json('data.id');
    $other = new GenericUser(['id' => 'mobile-user-2', 'project_ids' => [$this->project]]);
    $this->actingAs($other)->deleteJson("/api/v1/push-devices/{$device}")->assertNotFound();
    $this->deleteJson('/api/v1/push-devices/'.Str::uuid())->assertNotFound();
})->group('AC-push-mobile-widget-status-1');

it('deactivates a superseded token and selects only active current registrations', function (): void {
    $this->actingAs($this->user)->postJson('/api/v1/push-devices', $this->validDevice)->assertCreated();
    $otherUser = new GenericUser(['id' => 'mobile-user-2', 'project_ids' => [$this->project]]);
    $this->actingAs($otherUser)->postJson('/api/v1/push-devices', [
        ...$this->validDevice,
        'installation_id' => (string) Str::uuid(),
    ])->assertCreated();

    expect(PushDevice::query()->count())->toBe(2)
        ->and(PushDevice::query()->where('active', true)->count())->toBe(1)
        ->and(PushDevice::query()->where('active', true)->sole()->user_id)->toBe('mobile-user-2');
})->group('AC-push-mobile-widget-status-1');
