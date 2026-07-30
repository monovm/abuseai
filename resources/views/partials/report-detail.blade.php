{{-- Report header --}}
<div class="flex" style="justify-content:space-between; align-items:center; margin-bottom:10px;">
    <div>
        <strong>{{ $report->reporter?->name ?? 'Unknown' }}</strong>
        <span class="text-muted" style="margin-left:6px;">{{ $report->reporter?->email ?? '' }}</span>
        @if(! empty($report->metadata['forwarded_by']))
            <div class="text-sm text-muted" style="margin-top:2px;">
                <span class="badge badge-open" style="margin-right:4px;" title="Original sender to our inbox">Forwarded by</span>
                @if(! empty($report->metadata['forwarded_by_name']))
                    {{ $report->metadata['forwarded_by_name'] }}
                    <span class="text-muted" style="margin-left:4px;">&lt;{{ $report->metadata['forwarded_by'] }}&gt;</span>
                @else
                    {{ $report->metadata['forwarded_by'] }}
                @endif
                @if(! empty($report->metadata['forward_detection_source']))
                    <span class="text-muted" style="margin-left:6px;">({{ str_replace('_', ' ', $report->metadata['forward_detection_source']) }})</span>
                @endif
            </div>
        @endif
    </div>
    <div class="flex gap-2" style="align-items:center;">
        @if($report->ai_classification)
            <span class="badge badge-medium">AI: {{ $report->ai_classification['type'] ?? '?' }} ({{ number_format(($report->ai_classification['confidence'] ?? 0) * 100) }}%)</span>
        @endif
        @if($report->ai_noise_score !== null)
            <span class="badge {{ $report->ai_noise_score > 0.5 ? 'badge-closed' : 'badge-resolved' }}">Noise: {{ number_format($report->ai_noise_score * 100) }}%</span>
        @endif
        @if($report->is_duplicate)
            <span class="badge badge-closed">Duplicate</span>
        @endif
        <span class="text-muted">{{ ($report->reported_at ?? $report->created_at)->format('M j, g:ia') }}</span>
    </div>
</div>

{{-- Email subject (when the report came from email — surfaced because
     analysts scan reports by subject and otherwise it's buried in the
     Headers details below). --}}
@php
    $reportSubject = is_array($report->headers ?? null) ? trim((string) ($report->headers['subject'] ?? '')) : '';
@endphp
@if($reportSubject !== '')
    <div style="margin-bottom:8px; font-size:13.5px; line-height:1.4;">
        <span class="text-muted" style="font-size:11px; text-transform:uppercase; letter-spacing:0.04em; margin-right:6px;">Subject</span>
        <strong style="word-break:break-word;">{{ $reportSubject }}</strong>
    </div>
@endif

{{-- Report meta --}}
<div class="text-sm text-muted" style="margin-bottom:8px;">
    Source: <span class="badge badge-open">{{ $report->source }}</span>
    &middot; Type: {{ ucfirst(is_string($report->abuse_type) ? $report->abuse_type : $report->abuse_type->value) }}
    @if($report->target_ip) &middot; IP: <strong style="font-family:monospace;">{{ $report->target_ip }}</strong> @endif
    @if(! empty($report->extra_target_ips))
        &middot; Extra IPs:
        @foreach($report->extra_target_ips as $i => $extraIp)
            <span style="font-family:monospace; padding:1px 5px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:3px;">{{ $extraIp }}</span>{{ $i < count($report->extra_target_ips) - 1 ? ' ' : '' }}
        @endforeach
    @endif
    @if($report->target_domain) &middot; Domain: <strong>{{ $report->target_domain }}</strong> @endif
    @if($report->target_url) &middot; URL: <span style="word-break:break-all;">{{ Str::limit($report->target_url, 60) }}</span> @endif
</div>

{{-- External case / ticket numbers (police, CERT, DMCA, ISP, etc.) --}}
@if(! empty($report->external_case_numbers))
    <div style="display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-bottom:8px;">
        @foreach($report->external_case_numbers as $ref)
            @if(! empty($ref['value']))
                <span style="display:inline-flex; align-items:center; gap:4px; padding:3px 8px; background:#ecfeff; border:1px solid #67e8f9; border-radius:4px; font-size:12px;">
                    <span style="color:#0e7490; font-weight:600;">{{ $ref['label'] ?? 'Reference' }}:</span>
                    <span style="font-family:monospace;">{{ $ref['value'] }}</span>
                </span>
            @endif
        @endforeach
    </div>
@endif

{{-- Abuse occurrence time — when the attack/incident actually happened
     (NOT when the report was received). Highlighted because incident
     responders care more about this than reported_at. --}}
@if($report->abuse_occurred_at)
    <div style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; background:#fef3c7; border:1px solid #fcd34d; border-radius:6px; margin-bottom:8px; font-size:13px;">
        <span style="font-size:14px;">&#9200;</span>
        <strong>Abuse date:</strong>
        <span style="font-family:monospace;">{{ $report->abuse_occurred_at->format('Y-m-d H:i:s T') }}</span>
        <span class="text-muted">({{ $report->abuse_occurred_at->diffForHumans() }})</span>
    </div>
@endif

{{-- AI Summary --}}
@if($report->ai_classification && isset($report->ai_classification['summary']))
    <div style="padding:8px 12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; margin-bottom:8px; font-size:13px;">
        <strong>AI Summary:</strong> {{ $report->ai_classification['summary'] }}
    </div>
@endif

{{-- Case creation status log (when no case is linked) --}}
@if(! $report->case_id)
    <div style="padding:8px 12px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; margin-bottom:8px; font-size:13px;">
        <strong>Case Not Created:</strong>
        @if($report->metadata['flagged_as_not_abuse'] ?? false)
            AI classified as not abuse{{ ($report->metadata['not_abuse_reason'] ?? '') ? ': ' . $report->metadata['not_abuse_reason'] : '' }}
        @elseif($report->is_duplicate)
            Duplicate report ({{ $report->metadata['dedup_level'] ?? 'matched' }} match)
        @elseif($report->metadata['skipped_reason'] ?? null)
            {{ str_replace('_', ' ', ucfirst($report->metadata['skipped_reason'])) }}
        @elseif(! $report->target_ip && ! $report->target_domain)
            No target IP or domain found in report
        @else
            Pending processing or unknown reason
        @endif
    </div>
@endif

{{-- Not-abuse reason (additional detail) --}}
@if(($report->metadata['not_abuse_reason'] ?? null) && $report->case_id)
    <div style="padding:8px 12px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; margin-bottom:8px; font-size:13px;">
        <strong>Not Abuse Reason:</strong> {{ $report->metadata['not_abuse_reason'] }}
    </div>
@endif

{{-- Skip reason (additional detail) --}}
@if(($report->metadata['skipped_reason'] ?? null) && $report->case_id)
    <div style="padding:8px 12px; background:#fefce8; border:1px solid #fde68a; border-radius:6px; margin-bottom:8px; font-size:13px;">
        <strong>Skip Reason:</strong> {{ str_replace('_', ' ', ucfirst($report->metadata['skipped_reason'])) }}
    </div>
@endif

{{-- Evidence --}}
@if($report->evidence)
    <details style="margin-top:8px;">
        <summary style="cursor:pointer; color:#2563eb; font-size:12px; user-select:none;">
            View evidence ({{ number_format(strlen($report->evidence)) }} chars)
        </summary>
        <div style="margin-top:6px; padding:10px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; max-height:400px; overflow-y:auto; white-space:pre-wrap; word-wrap:break-word; font-family:monospace; font-size:12px; line-height:1.5;">{{ $report->evidence }}</div>
    </details>
@endif

{{-- Raw payload --}}
@if($report->raw_payload && $report->raw_payload !== $report->evidence)
    <details style="margin-top:4px;">
        <summary style="cursor:pointer; color:#6b7280; font-size:12px; user-select:none;">
            Raw payload ({{ number_format(strlen($report->raw_payload)) }} chars)
        </summary>
        <div style="margin-top:6px; padding:10px; background:#fefce8; border:1px solid #fde68a; border-radius:6px; max-height:300px; overflow-y:auto; white-space:pre-wrap; word-wrap:break-word; font-family:monospace; font-size:11px; line-height:1.5;">{{ $report->raw_payload }}</div>
    </details>
@endif

{{-- Email headers --}}
@if($report->headers)
    <details style="margin-top:4px;">
        <summary style="cursor:pointer; color:#6b7280; font-size:12px; user-select:none;">
            Headers
        </summary>
        <div style="margin-top:6px; padding:10px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; font-size:12px;">
            @foreach($report->headers as $key => $value)
                <div><strong>{{ $key }}:</strong> {{ is_array($value) ? json_encode($value) : $value }}</div>
            @endforeach
        </div>
    </details>
@endif

{{-- Enrichment / IOCs --}}
@if($report->enrichment)
    <details style="margin-top:4px;">
        <summary style="cursor:pointer; color:#6b7280; font-size:12px; user-select:none;">
            Enrichment data
        </summary>
        <div style="margin-top:6px; padding:10px; background:#f5f3ff; border:1px solid #ddd6fe; border-radius:6px; font-size:12px;">
            @if(isset($report->enrichment['parsed_iocs']))
                @php
                    $iocs = $report->enrichment['parsed_iocs'];
                    $tIps = $iocs['target_ips'] ?? $iocs['ips'] ?? [];
                    $tDomains = $iocs['target_domains'] ?? $iocs['domains'] ?? [];
                    $tUrls = $iocs['target_urls'] ?? $iocs['urls'] ?? [];
                    $rIps = $iocs['reporter_ips'] ?? [];
                    $rDomains = $iocs['reporter_domains'] ?? [];
                @endphp
                @if(! empty($iocs['issue_summary']))
                    <div><strong>Issue:</strong> {{ $iocs['issue_summary'] }}</div>
                @endif
                @if(! empty($tIps))
                    <div><strong>Target IPs:</strong> {{ implode(', ', $tIps) }}</div>
                @endif
                @if(! empty($tDomains))
                    <div><strong>Target Domains:</strong> {{ implode(', ', $tDomains) }}</div>
                @endif
                @if(! empty($tUrls))
                    <div><strong>Target URLs:</strong> {{ implode(', ', $tUrls) }}</div>
                @endif
                @if(! empty($rIps))
                    <div class="text-muted"><strong>Reporter IPs:</strong> {{ implode(', ', $rIps) }}</div>
                @endif
                @if(! empty($rDomains))
                    <div class="text-muted"><strong>Reporter Domains:</strong> {{ implode(', ', $rDomains) }}</div>
                @endif
                @if(! empty($iocs['evidence_summary']))
                    <div style="margin-top:4px;"><strong>Summary:</strong> {{ $iocs['evidence_summary'] }}</div>
                @endif
            @endif
            @if(isset($report->enrichment['ip_intel']['threat_score']))
                <div style="margin-top:4px;"><strong>Threat Score:</strong> {{ $report->enrichment['ip_intel']['threat_score'] }}%</div>
            @endif
            @if(isset($report->enrichment['rdns']))
                <div><strong>rDNS:</strong> {{ $report->enrichment['rdns'] }}</div>
            @endif
        </div>
    </details>
@endif

{{-- Attachments --}}
@if($report->attachment_paths)
    <div style="margin-top:8px;">
        <div class="text-sm text-muted" style="margin-bottom:4px;">
            Attachments ({{ count($report->attachment_paths) }})
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:6px;">
            @foreach($report->attachment_paths as $idx => $path)
                <a href="{{ route('admin.reports.attachments.download', ['report' => $report->id, 'index' => $idx]) }}"
                    style="display:inline-flex; align-items:center; gap:4px; padding:4px 8px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:4px; font-size:12px; color:#2563eb; text-decoration:none; word-break:break-all;"
                    title="Download {{ $path }}">
                    <span style="font-size:11px;">&darr;</span>
                    <code style="background:none; padding:0;">{{ basename($path) }}</code>
                </a>
            @endforeach
        </div>
    </div>
@endif

{{-- Metadata flags --}}
@if($report->metadata && (($report->metadata['translated'] ?? false) || ($report->metadata['ip_in_inventory'] ?? null) !== null))
    <div class="text-sm" style="margin-top:6px;">
        @if($report->metadata['translated'] ?? false)
            <span class="badge" style="background:#fef3c7; color:#92400e;">Translated from {{ $report->metadata['language_name'] ?? $report->metadata['original_language'] ?? '?' }}</span>
        @endif
        @if(($report->metadata['ip_in_inventory'] ?? null) === true)
            @if(($report->metadata['ip_active'] ?? true) === true)
                <span class="badge badge-resolved">IP in inventory (active)</span>
            @else
                <span class="badge" style="background:#fef3c7; color:#92400e;">IP in inventory ({{ $report->metadata['ip_status'] ?? 'inactive' }})</span>
            @endif
        @elseif(($report->metadata['ip_in_inventory'] ?? null) === false)
            <span class="badge badge-closed">IP not in inventory</span>
        @endif
        @if($report->metadata['ip_server_name'] ?? null)
            <span class="text-muted">Server: {{ $report->metadata['ip_server_name'] }}</span>
        @endif
    </div>
@endif
