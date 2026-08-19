<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Illuminate\Foundation\Exceptions\Handler;
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

    public function test_dispatches_job_with_multiple_comma_separated_emails(): void
    {
        Queue::fake();

        $this->artisan('queue-health:test', ['email' => 'user1@example.com,user2@example.com'])
            ->assertSuccessful();

        Queue::assertPushed(QueueHealthTestJob::class, function ($job) {
            return (new \ReflectionProperty($job, 'email'))->getValue($job) === 'user1@example.com,user2@example.com';
        });
    }

    public function test_dispatches_the_test_job_on_the_configured_connection_and_queue(): void
    {
        config()->set('queue-health.connection', 'redis');
        config()->set('queue-health.queue', 'monitoring');
        Queue::fake();

        $this->artisan('queue-health:test', ['email' => 'user@example.com'])
            ->assertSuccessful();

        Queue::assertPushed(QueueHealthTestJob::class, function ($job) {
            return $job->connection === 'redis' && $job->queue === 'monitoring';
        });
    }

    public function test_warns_when_the_queue_connection_is_sync(): void
    {
        config()->set('queue.default', 'sync');
        Queue::fake();

        $this->artisan('queue-health:test', ['email' => 'user@example.com'])
            ->expectsOutputToContain('sync driver')
            ->assertSuccessful();
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
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    private function expectsReport(string $exceptionClass): void
    {
        $this->app->singleton('Illuminate\Contracts\Debug\ExceptionHandler', function ($app) use ($exceptionClass) {
            $handler = Mockery::mock(Handler::class.'[report]', [$app]);
            $handler->shouldReceive('report')->with(Mockery::type($exceptionClass))->once();

            return $handler;
        });
    }
}
