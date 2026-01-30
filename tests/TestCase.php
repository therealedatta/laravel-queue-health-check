<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use TheRealEdatta\QueueHealthCheck\QueueHealthCheckServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [QueueHealthCheckServiceProvider::class];
    }
}
