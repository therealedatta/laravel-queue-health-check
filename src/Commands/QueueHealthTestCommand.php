<?php

namespace TheRealEdatta\QueueHealthCheck\Commands;

use Illuminate\Console\Command;
use TheRealEdatta\QueueHealthCheck\Exceptions\QueueHealthException;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthTestJob;

class QueueHealthTestCommand extends Command
{
    protected $signature = 'queue-health:test {email?}';

    protected $description = 'Dispatch a test email through the queue to verify it is working';

    public function handle(): void
    {
        $email = $this->argument('email') ?? config('queue-health.alert_email');

        if (! $email) {
            $this->error('No email provided. Pass an email argument or set QUEUE_HEALTH_ALERT_EMAIL.');
            report(new QueueHealthException(
                'queue-health:test failed: no email configured on '.gethostname()
            ));

            return;
        }

        QueueHealthTestJob::dispatch($email);

        $this->info("Test job dispatched to the queue. Check {$email} for the test email.");
    }
}
