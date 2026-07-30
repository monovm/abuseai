<?php

namespace App\Exceptions;

use App\Models\AuditLog;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

class QueueFailureHandler
{
    public function handle(JobFailed $event): void
    {
        $jobName = $event->job->resolveName();
        $connection = $event->connectionName;
        $queue = $event->job->getQueue();
        $exception = $event->exception;

        Log::critical('Queue job permanently failed', [
            'job' => $jobName,
            'connection' => $connection,
            'queue' => $queue,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Log to audit trail
        try {
            AuditLog::create([
                'entity_type' => 'QueueJob',
                'entity_id' => \Illuminate\Support\Str::uuid()->toString(),
                'event' => 'job_failed',
                'old_values' => [
                    'job' => $jobName,
                    'queue' => $queue,
                    'connection' => $connection,
                ],
                'new_values' => [
                    'error' => $exception->getMessage(),
                    'file' => $exception->getFile() . ':' . $exception->getLine(),
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Don't fail on audit log errors
        }
    }
}
