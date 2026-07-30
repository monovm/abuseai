<?php

namespace App\Observers;

use App\Models\AbuseReport;
use App\Models\AuditLog;

class AbuseReportObserver
{
    public function created(AbuseReport $report): void
    {
        AuditLog::create([
            'entity_type' => 'AbuseReport',
            'entity_id' => $report->id,
            'event' => 'created',
            'actor_id' => auth()->id(),
            'new_values' => [
                'source' => $report->source,
                'abuse_type' => is_string($report->abuse_type) ? $report->abuse_type : $report->abuse_type->value,
                'target_ip' => $report->target_ip,
                'reporter_id' => $report->reporter_id,
            ],
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
