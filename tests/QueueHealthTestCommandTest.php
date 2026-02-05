<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Illuminate\Support\Facades\Queue;
use Mockery;
use TheRealEdatta\QueueHealthCheck\Exceptions\QueueHealthException;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthTestJob;

class QueueHealthTestCommandTest extends TestCase
{
    public function test_dispatches_job_with_provided_email(): void
    {
        Queue::fake();

        $this->artisan('queue-health:test', ['email' => 'user@example.com'])
            ->assertSuccessful();

        Queue::assertPushed(QueueHealthTestJob::class);
    }

    public function test_uses_config_email_when_no_argument(): void
    {
        config()->set('queue-health.alert_email', 'admin@example.com');
        Queue::fake();

        $this->artisan('queue-health:test')
            ->assertSuccessful();

        Queue::assertPushed(QueueHealthTestJob::class);
    }

    public function test_reports_exception_when_no_email_and_no_config(): void
    {
        config()->set('queue-health.alert_email', null);
        Queue::fake();

        $this->expectsReport(QueueHealthException::class);

        $this->artisan('queue-health:test')
            ->expectsOutput('No email provided. Pass an email argument or set QUEUE_HEALTH_ALERT_EMAIL.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    private function expectsReport(string $exceptionClass): void
    {
        $this->app->bind('Illuminate\Contracts\Debug\ExceptionHandler', function ($app) use ($exceptionClass) {
            $handler = Mockery::mock(\Illuminate\Foundation\Exceptions\Handler::class.'[report]', [$app]);
            $handler->shouldReceive('report')->with(Mockery::type($exceptionClass))->once();

            return $handler;
        });
    }
}
