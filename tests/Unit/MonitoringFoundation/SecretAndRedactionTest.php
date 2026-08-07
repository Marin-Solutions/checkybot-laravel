<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\EncryptedHeaderValue;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\EncryptedSecret;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\ProjectTokenAbility;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

beforeEach(function (): void {
    config()->set('database.default', 'security_foundation_test');
    config()->set('database.connections.security_foundation_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('security_foundation_test');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
});

it('keeps secret and header plaintext behind masked encrypted value objects', function (): void {
    $encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $plainSecret = 'sk_live_plaintext_never_persist';
    $plainHeader = 'Bearer header-plaintext-never-persist';
    $secret = EncryptedSecret::encrypt($plainSecret, $encrypter);
    $header = EncryptedHeaderValue::encrypt($plainHeader, $encrypter);

    Schema::create('foundation_secret_probes', function (Blueprint $table): void {
        $table->id();
        $table->text('secret');
        $table->text('header');
    });
    DB::table('foundation_secret_probes')->insert([
        'secret' => $secret->ciphertext(),
        'header' => $header->ciphertext(),
    ]);
    $atRest = (array) DB::table('foundation_secret_probes')->first();
    $logStream = fopen('php://memory', 'w+');
    $handler = new StreamHandler($logStream);
    $handler->setFormatter(new JsonFormatter);
    (new Logger('security-test', [$handler]))->info('masked values', ['secret' => $secret, 'header' => $header]);
    rewind($logStream);
    $logged = stream_get_contents($logStream);
    fclose($logStream);

    expect($atRest['secret'])->not->toContain($plainSecret)
        ->and($atRest['header'])->not->toContain($plainHeader)
        ->and(EncryptedSecret::restore($atRest['secret'], $encrypter)->reveal())->toBe($plainSecret)
        ->and(EncryptedHeaderValue::restore($atRest['header'], $encrypter)->reveal())->toBe($plainHeader)
        ->and((string) $secret)->toBe('[REDACTED]')
        ->and((string) $header)->toBe('[REDACTED]')
        ->and(json_encode(['secret' => $secret, 'header' => $header], JSON_THROW_ON_ERROR))->toBe('{"secret":"[REDACTED]","header":"[REDACTED]"}')
        ->and(print_r([$secret, $header], true))->not->toContain($plainSecret, $plainHeader)
        ->and($logged)->toContain('[REDACTED]')->not->toContain($plainSecret, $plainHeader);
})->group('AC-domain-runtime-foundation-6');

it('hashes project tokens and enforces ability expiry revocation and rotation', function (): void {
    $projectId = (string) Str::uuid();
    $issued = ProjectApiToken::issue(
        $projectId,
        'widget',
        [ProjectTokenAbility::StatusRead],
        now()->addHour(),
    );
    $plainText = $issued->plainTextToken();
    $persisted = DB::table('project_api_tokens')->where('id', $issued->accessToken->getKey())->first();

    expect((array) $persisted)->not->toContain($plainText)
        ->and($persisted->token_hash)->toBe(hash('sha256', $plainText))
        ->and(ProjectApiToken::authenticate($plainText)?->is($issued->accessToken))->toBeTrue()
        ->and($issued->accessToken->allows('status:read'))->toBeTrue()
        ->and($issued->accessToken->allows('checks:write'))->toBeFalse()
        ->and(json_encode($issued, JSON_THROW_ON_ERROR))->not->toContain($plainText);

    $expired = ProjectApiToken::issue($projectId, 'expired', ['status:read'], now()->subSecond());
    expect(ProjectApiToken::authenticate($expired->plainTextToken()))->toBeNull();

    $revokedPlainText = $plainText;
    $rotated = $issued->accessToken->rotate();
    expect(ProjectApiToken::authenticate($revokedPlainText))->toBeNull()
        ->and(ProjectApiToken::authenticate($rotated->plainTextToken())?->allows('status:read'))->toBeTrue()
        ->and($rotated->plainTextToken())->not->toBe($revokedPlainText);

    $rotated->accessToken->revoke();
    expect(ProjectApiToken::authenticate($rotated->plainTextToken()))->toBeNull();
})->group('AC-domain-runtime-foundation-6');

it('recursively redacts the fixed corpus without destroying diagnostics', function (): void {
    $secrets = [
        'query-one', 'query-two', 'bearer-secret', 'cookie-secret',
        'configured-literal-secret', 'operator@example.test', '192.0.2.44',
        '2001:db8:85a3::8a2e:370:7334',
    ];
    $input = [
        'status' => 'timeout',
        'code' => 504,
        'request' => 'GET https://status.example.test/check?token=query-one&mode=query-two',
        'headers' => [
            'Authorization' => 'Bearer bearer-secret',
            'Cookie' => 'session=cookie-secret',
            'X-Diagnostic' => 'retryable',
        ],
        'nested' => [
            'configured-literal-secret and operator@example.test',
            ['network' => 'from 192.0.2.44 to 2001:db8:85a3::8a2e:370:7334'],
            'Authorization: Bearer bearer-secret',
            'Cookie: session=cookie-secret',
        ],
    ];

    $redacted = (new RecursiveRedactor(['configured-literal-secret']))->redact($input);
    $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);

    foreach ($secrets as $secret) {
        expect($encoded)->not->toContain($secret);
    }

    expect($redacted['status'])->toBe('timeout')
        ->and($redacted['code'])->toBe(504)
        ->and($redacted['headers']['X-Diagnostic'])->toBe('retryable')
        ->and($redacted['request'])->toContain('status.example.test/check', 'token=[REDACTED]', 'mode=[REDACTED]');
})->group('AC-domain-runtime-foundation-7');
