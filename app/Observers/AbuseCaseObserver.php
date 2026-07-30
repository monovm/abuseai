<?php

namespace App\Observers;

use App\Models\AbuseCase;
use App\Models\AuditLog;
use Illuminate\Support\Str;

class AbuseCaseObserver
{
    public function created(AbuseCase $case): void
    {
        $this->log($case, 'created', null, $case->toArray());
    }

    public function updated(AbuseCase $case): void
    {
        $dirty = $case->getDirty();
        $original = array_intersect_key($case->getOriginal(), $dirty);

        if (empty($dirty)) {
            return;
        }

        $this->log($case, 'updated', $original, $dirty);
    }

    public function deleted(AbuseCase $case): void
    {
        $this->log($case, 'deleted', $case->toArray(), null);
    }

    protected function log(AbuseCase $case, string $event, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'entity_type' => 'AbuseCase',
            'entity_id' => $case->id,
            'event' => $event,
            'actor_id' => auth()->id(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
