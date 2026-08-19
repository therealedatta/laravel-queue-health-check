<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Illuminate\Console\Scheduling\Schedule;

class ScheduleTest extends TestCase
{
    public function test_schedules_the_check_on_the_configured_interval(): void
    {
        config()->set('queue-health.check_interval_minutes', 10);

        $this->assertEquals('*/10 * * * *', $this->checkExpression());
    }

    public function test_clamps_the_interval_to_what_cron_can_express(): void
    {
        config()->set('queue-health.check_interval_minutes', 120);

        $this->assertEquals('*/59 * * * *', $this->checkExpression());
    }

    private function checkExpression(): ?string
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'queue-health:check')) {
                return $event->expression;
            }
        }

        return null;
    }
}
