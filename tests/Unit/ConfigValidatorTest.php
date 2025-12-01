<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Tests\Unit;

use MarinSolutions\CheckybotLaravel\ConfigValidator;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;

class ConfigValidatorTest extends TestCase
{
    protected ConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ConfigValidator;
    }

    public function test_validates_missing_api_key(): void
    {
        $config = [
            'project_id' => '1',
            'checks' => [],
        ];

        $result = $this->validator->validate($config);

        $this->assertFalse($result['valid']);
        $this->assertContains('CHECKYBOT_API_KEY is not configured', $result['errors']);
    }

    public function test_validates_missing_project_id(): void
    {
        $config = [
            'api_key' => 'test-key',
            'checks' => [],
        ];

        $result = $this->validator->validate($config);

        $this->assertFalse($result['valid']);
        $this->assertContains('CHECKYBOT_PROJECT_ID is not configured', $result['errors']);
    }

    public function test_validates_duplicate_check_names(): void
    {
        $config = [
            'api_key' => 'test-key',
            'project_id' => '1',
            'checks' => [
                'uptime' => [
                    ['name' => 'duplicate', 'url' => 'https://example.com', 'interval' => '5m'],
                    ['name' => 'duplicate', 'url' => 'https://example2.com', 'interval' => '5m'],
                ],
            ],
        ];

        $result = $this->validator->validate($config);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Duplicate uptime check names found', $result['errors'][0]);
    }

    public function test_validates_valid_config(): void
    {
        $config = [
            'api_key' => 'test-key',
            'project_id' => '1',
            'checks' => [
                'uptime' => [
                    ['name' => 'check1', 'url' => 'https://example.com', 'interval' => '5m'],
                ],
                'ssl' => [],
                'api' => [],
            ],
        ];

        $result = $this->validator->validate($config);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_transforms_payload_correctly(): void
    {
        $config = [
            'checks' => [
                'uptime' => [
                    ['name' => 'check1', 'url' => 'https://example.com', 'interval' => '5m'],
                ],
                'ssl' => [
                    ['name' => 'check2', 'url' => 'https://example.com', 'interval' => '1d'],
                ],
                'api' => [],
            ],
        ];

        $result = $this->validator->transformPayload($config);

        $this->assertArrayHasKey('uptime_checks', $result);
        $this->assertArrayHasKey('ssl_checks', $result);
        $this->assertArrayHasKey('api_checks', $result);
        $this->assertCount(1, $result['uptime_checks']);
        $this->assertCount(1, $result['ssl_checks']);
        $this->assertCount(0, $result['api_checks']);
    }
}
