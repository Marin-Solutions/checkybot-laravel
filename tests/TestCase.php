<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Tests;

use MarinSolutions\CheckybotLaravel\CheckybotLaravelServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            CheckybotLaravelServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }
}
