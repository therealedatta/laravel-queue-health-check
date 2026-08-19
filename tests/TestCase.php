<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Mockery;
use Orchestra\Testbench\TestCase as BaseTestCase;
use TheRealEdatta\QueueHealthCheck\QueueHealthCheckServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [QueueHealthCheckServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'database');
    }

    /**
     * Bound as a singleton so every report() in one run reaches the same mock.
     */
    protected function expectsReport(string ...$exceptionClasses): void
    {
        $this->app->singleton(ExceptionHandler::class, function ($app) use ($exceptionClasses) {
            $handler = Mockery::mock(Handler::class.'[report]', [$app]);

            foreach ($exceptionClasses as $exceptionClass) {
                $handler->shouldReceive('report')->with(Mockery::type($exceptionClass))->once();
            }

            return $handler;
        });
    }
}
