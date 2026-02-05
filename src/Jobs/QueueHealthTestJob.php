<?php

namespace TheRealEdatta\QueueHealthCheck\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class QueueHealthTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    private string $dispatchedAt;

    public function __construct(
        private string $email
    ) {
        $this->dispatchedAt = now()->format('Y-m-d H:i:s');
    }

    public function handle(): void
    {
        $dispatchedAt = Carbon::parse($this->dispatchedAt);
        $processedAt = now();
        $delaySeconds = (int) $dispatchedAt->diffInSeconds($processedAt);

        $isDelayed = $delaySeconds > 60;

        $subject = $isDelayed
            ? '['.config('app.name').'] Queue test: working, but with delay'
            : '['.config('app.name').'] Queue test: everything is working';

        $statusLine = $isDelayed
            ? "⚠️ The queue is working, but with a delay of {$delaySeconds}s."
            : '✅ If you are reading this, the queue is working correctly.';

        $body = "This is a test email to verify that the job queue is working correctly.\n\n"
            ."{$statusLine}\n\n"
            ."Dispatched: {$this->dispatchedAt}\n"
            ."Processed: {$processedAt->format('Y-m-d H:i:s')}\n"
            ."Delay: {$delaySeconds}s\n\n"
            .'Server: '.gethostname();

        Mail::raw(
            $body,
            function ($message) use ($subject) {
                $message->to($this->email)
                    ->subject($subject);
            }
        );
    }
}
