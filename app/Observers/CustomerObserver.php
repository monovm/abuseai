<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Customer;

class CustomerObserver
{
    public function updated(Customer $customer): void
    {
        $dirty = $customer->getDirty();
        $original = array_intersect_key($customer->getOriginal(), $dirty);

        if (empty($dirty)) {
            return;
        }

        AuditLog::create([
            'entity_type' => 'Customer',
            'entity_id' => $customer->id,
            'event' => 'updated',
            'actor_id' => auth()->id(),
            'old_values' => $original,
            'new_values' => $dirty,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
