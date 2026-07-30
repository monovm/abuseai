<?php

namespace App\Services\AI;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AiUsageService
{
    public function totals(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $row = $this->baseQuery($from, $to)
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->first();

        return [
            'calls'         => (int) ($row->calls ?? 0),
            'input_tokens'  => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'total_tokens'  => (int) (($row->input_tokens ?? 0) + ($row->output_tokens ?? 0)),
            'cost_usd'      => (float) ($row->cost_usd ?? 0),
        ];
    }

    public function byProviderModel(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        return $this->baseQuery($from, $to)
            ->selectRaw('provider, model, COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->groupBy('provider', 'model')
            ->orderByDesc('cost_usd')
            ->get();
    }

    public function daily(int $days = 30): Collection
    {
        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();

        return $this->baseQuery($from, null)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    protected function baseQuery(?CarbonImmutable $from, ?CarbonImmutable $to)
    {
        $query = AuditLog::query()->where('event', 'ai_call');

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<', $to);
        }

        return $query;
    }
}
