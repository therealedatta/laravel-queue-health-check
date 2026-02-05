<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Carbon\Carbon;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Mockery;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthTestJob;

class QueueHealthTestJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sends_test_email_with_timing_info(): void
    {
        Carbon::setTestNow('2024-01-15 10:00:00');
        $job = new QueueHealthTestJob('user@example.com');

        Carbon::setTestNow('2024-01-15 10:00:05');

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $this->assertStringContainsString('test email', $text);
            $this->assertStringContainsString('queue is working correctly', $text);
            $this->assertStringContainsString('Dispatched: 2024-01-15 10:00:00', $text);
            $this->assertStringContainsString('Processed: 2024-01-15 10:00:05', $text);
            $this->assertStringContainsString('Delay: 5s', $text);

            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->with('user@example.com')->andReturnSelf();
            $message->shouldReceive('subject')->with(Mockery::on(fn ($s) => str_contains($s, 'everything is working')))->andReturnSelf();
            $callback($message);

            return true;
        });

        $job->handle();
    }

    public function test_warns_when_queue_is_delayed(): void
    {
        Carbon::setTestNow('2024-01-15 10:00:00');
        $job = new QueueHealthTestJob('user@example.com');

        Carbon::setTestNow('2024-01-15 10:03:00');

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $this->assertStringContainsString('delay of 180s', $text);

            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->with('user@example.com')->andReturnSelf();
            $message->shouldReceive('subject')->with(Mockery::on(fn ($s) => str_contains($s, 'but with delay')))->andReturnSelf();
            $callback($message);

            return true;
        });

        $job->handle();
    }
}
