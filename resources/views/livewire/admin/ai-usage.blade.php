<div>
    {{-- Top-line totals --}}
    <div class="resp-grid-2" style="display:grid; grid-template-columns:repeat(3, 1fr); gap:1rem; margin-bottom:1rem;">
        @php
            $cards = [
                ['label' => 'Today',            'data' => $totalsToday],
                ['label' => 'Last 7 days',      'data' => $totals7d],
                ['label' => "Last {$days} days", 'data' => $totalsPeriod],
            ];
        @endphp

        @foreach($cards as $card)
            <div class="card">
                <div class="card-title" style="margin-bottom:0.5rem;">{{ $card['label'] }}</div>
                <div style="font-size:1.5rem; font-weight:700;">${{ number_format($card['data']['cost_usd'], 4) }}</div>
                <div class="text-sm text-muted" style="margin-top:0.375rem;">
                    {{ number_format($card['data']['calls']) }} calls &middot;
                    {{ number_format($card['data']['total_tokens']) }} tokens
                </div>
                <div class="text-sm text-muted">
                    in {{ number_format($card['data']['input_tokens']) }} / out {{ number_format($card['data']['output_tokens']) }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- Range selector --}}
    <div class="card" style="margin-bottom:1rem;">
        <div style="display:flex; align-items:center; gap:0.75rem;">
            <label class="text-sm" style="font-weight:600;">Period:</label>
            <select wire:model.live="days" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                <option value="7">Last 7 days</option>
                <option value="14">Last 14 days</option>
                <option value="30">Last 30 days</option>
                <option value="60">Last 60 days</option>
                <option value="90">Last 90 days</option>
            </select>
        </div>
    </div>

    {{-- Breakdown by provider + model --}}
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-title" style="margin-bottom:0.75rem;">By provider &amp; model (last {{ $days }} days)</div>
        @if($byProviderModel->isEmpty())
            <div class="text-sm text-muted">No AI calls recorded in this period.</div>
        @else
            <table style="width:100%; font-size:0.8125rem; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); text-align:left;">
                        <th style="padding:0.5rem;">Provider</th>
                        <th style="padding:0.5rem;">Model</th>
                        <th style="padding:0.5rem; text-align:right;">Calls</th>
                        <th style="padding:0.5rem; text-align:right;">Input tokens</th>
                        <th style="padding:0.5rem; text-align:right;">Output tokens</th>
                        <th style="padding:0.5rem; text-align:right;">Cost (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($byProviderModel as $row)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:0.5rem;">{{ $row->provider ?? '—' }}</td>
                            <td style="padding:0.5rem;">{{ $row->model ?? '—' }}</td>
                            <td style="padding:0.5rem; text-align:right;">{{ number_format($row->calls) }}</td>
                            <td style="padding:0.5rem; text-align:right;">{{ number_format($row->input_tokens) }}</td>
                            <td style="padding:0.5rem; text-align:right;">{{ number_format($row->output_tokens) }}</td>
                            <td style="padding:0.5rem; text-align:right;">${{ number_format($row->cost_usd, 4) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Daily breakdown --}}
    <div class="card">
        <div class="card-title" style="margin-bottom:0.75rem;">Daily usage (last {{ $days }} days)</div>
        @if($daily->isEmpty())
            <div class="text-sm text-muted">No AI calls recorded in this period.</div>
        @else
            <table style="width:100%; font-size:0.8125rem; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); text-align:left;">
                        <th style="padding:0.5rem;">Date</th>
                        <th style="padding:0.5rem; text-align:right;">Calls</th>
                        <th style="padding:0.5rem; text-align:right;">Input tokens</th>
                        <th style="padding:0.5rem; text-align:right;">Output tokens</th>
                        <th style="padding:0.5rem; text-align:right;">Cost (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($daily as $row)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:0.5rem;">{{ $row->day }}</td>
                            <td style="padding:0.5rem; text-align:right;">{{ number_format($row->calls) }}</td>
                            <td style="padding:0.5rem; text-align:right;">{{ number_format($row->input_tokens) }}</td>
                            <td style="padding:0.5rem; text-align:right;">{{ number_format($row->output_tokens) }}</td>
                            <td style="padding:0.5rem; text-align:right;">${{ number_format($row->cost_usd, 4) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card" style="background:#f9fafb; margin-top:1rem;">
        <div class="card-title" style="margin-bottom:0.5rem;">Notes</div>
        <ul class="text-sm" style="padding-left:1.25rem; color:var(--text-muted); line-height:1.8;">
            <li>Tokens and cost are recorded directly from each provider's API response (<code>usage</code> block).</li>
            <li>Cost is computed from <code>config/ai.pricing</code>. Update rates there if a provider changes pricing.</li>
            <li>Models not present in the pricing table record cost as $0 — tokens are still tracked.</li>
            <li>Only calls logged when <code>abusedesk.ai.log_calls</code> is enabled appear here.</li>
        </ul>
    </div>
</div>
