<?php

namespace App\Livewire\Admin;

use App\Services\AI\AiUsageService;
use Carbon\CarbonImmutable;
use Livewire\Component;

class AiUsage extends Component
{
    public int $days = 30;

    public function updatingDays(&$value): void
    {
        $value = max(1, min((int) $value, 365));
    }

    public function render()
    {
        $usage = app(AiUsageService::class);

        $now       = CarbonImmutable::now();
        $today     = $now->startOfDay();
        $last7     = $now->subDays(6)->startOfDay();
        $lastN     = $now->subDays($this->days - 1)->startOfDay();

        return view('livewire.admin.ai-usage', [
            'totalsToday'    => $usage->totals($today, null),
            'totals7d'       => $usage->totals($last7, null),
            'totalsPeriod'   => $usage->totals($lastN, null),
            'byProviderModel' => $usage->byProviderModel($lastN, null),
            'daily'          => $usage->daily($this->days),
        ]);
    }
}
