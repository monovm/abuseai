<div class="grid-2">
    {{-- Left column: Case info + timeline --}}
    <div>
        {{-- Case header --}}
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">{{ $case->case_number }}</div>
                    <div class="text-sm text-muted mt-4">{{ ucfirst($case->abuse_type->value) }} &middot; Opened {{ $case->created_at->format('M j, Y g:ia') }}</div>
                </div>
                <div class="flex gap-2">
                    <span class="badge badge-{{ $case->severity_level->value }}">Score: {{ number_format($case->severity_score, 1) }}</span>
                    <span class="badge badge-{{ $case->status->value }}">{{ ucfirst($case->status->value) }}</span>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; font-size:0.8125rem;">
                <div>
                    <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Target IP</div>
                    @if($showIpForm)
                        <form wire:submit.prevent="attachTargetIp" style="display:flex; gap:4px; align-items:flex-start; margin-top:2px;">
                            <div style="flex:1;">
                                <input type="text" wire:model="manualTargetIp"
                                    placeholder="e.g. 192.0.2.10"
                                    style="width:100%; padding:4px 8px; border:1px solid #e5e7eb; border-radius:4px; font-size:12px;">
                                @error('manualTargetIp')
                                    <div style="color:#dc2626; font-size:11px; margin-top:2px;">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px; padding:3px 8px;"
                                wire:loading.attr="disabled" wire:target="attachTargetIp">
                                <span wire:loading.remove wire:target="attachTargetIp">Save</span>
                                <span wire:loading wire:target="attachTargetIp">…</span>
                            </button>
                            <button type="button" wire:click="cancelIpForm" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;">Cancel</button>
                        </form>
                    @else
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                            <span style="font-family:monospace;">{{ $case->target_ip ?? '-' }}</span>
                            <button wire:click="openIpForm" type="button"
                                style="background:none; border:none; color:#2563eb; font-size:11px; cursor:pointer; padding:0;">
                                {{ $case->target_ip ? 'edit' : 'attach' }}
                            </button>
                        </div>
                        @if(! empty($case->extra_target_ips))
                            <div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:4px;" title="Additional IPs found in the same report — same case, listed for context.">
                                @foreach($case->extra_target_ips as $extraIp)
                                    <span style="font-family:monospace; font-size:11px; padding:1px 6px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:3px; color:#3730a3;">{{ $extraIp }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if(session('ip-success'))
                            <div style="color:#065f46; font-size:11px; margin-top:2px;">{{ session('ip-success') }}</div>
                        @endif
                    @endif
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Target Domain</div>
                    <div>{{ $case->target_domain ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Reports</div>
                    <div>{{ $case->report_count }}</div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Customer</div>
                    <div>
                        @if($case->customer)
                            <a href="/admin/customers/{{ $case->customer_id }}" style="color:var(--primary);">{{ $case->customer->email }}</a>
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">SLA Deadline</div>
                    <div class="{{ $case->sla_deadline?->isPast() ? 'text-danger' : '' }}">
                        {{ $case->sla_deadline?->format('M j, g:ia') ?? '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">External References</div>
                    @if(! empty($case->external_case_numbers))
                        <div style="display:flex; flex-wrap:wrap; gap:4px;">
                            @foreach($case->external_case_numbers as $ref)
                                @if(! empty($ref['value']))
                                    <span style="display:inline-flex; align-items:center; gap:3px; padding:2px 6px; background:#ecfeff; border:1px solid #67e8f9; border-radius:3px; font-size:11px;"
                                        title="{{ $ref['label'] ?? 'Reference' }}">
                                        <span style="color:#0e7490; font-weight:600;">{{ $ref['label'] ?? 'Ref' }}:</span>
                                        <span style="font-family:monospace;">{{ $ref['value'] }}</span>
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="text-muted">-</div>
                    @endif
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Abuse Date</div>
                    @php $abuseOccurredAt = $case->effective_abuse_occurred_at; @endphp
                    @if($abuseOccurredAt)
                        <div style="display:inline-flex; align-items:center; gap:4px; padding:2px 6px; background:#fef3c7; border:1px solid #fcd34d; border-radius:4px; font-family:monospace; font-size:12px;"
                            title="{{ $abuseOccurredAt->format('Y-m-d H:i:s T') }} ({{ $abuseOccurredAt->diffForHumans() }})">
                            <span>&#9200;</span>
                            {{ $abuseOccurredAt->format('M j, Y g:ia') }}
                            <span class="text-muted" style="font-weight:normal;">({{ $abuseOccurredAt->diffForHumans() }})</span>
                        </div>
                    @else
                        <div class="text-muted">-</div>
                    @endif
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.6875rem; text-transform:uppercase;">Legal Hold</div>
                    <div>
                        @if($case->is_legal_hold)
                            <span class="badge badge-critical">Yes</span>
                        @else
                            <span class="badge badge-closed">No</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Brand Assignment --}}
        <div class="card">
            <div class="card-header">
                <div class="card-title">Brand</div>
                @if($case->brand_id)
                    <button wire:click="unassignBrand" class="btn btn-secondary btn-sm" style="font-size:11px;">Remove</button>
                @endif
            </div>
            <div style="font-size:11px; color:#6b7280; margin-bottom:6px;">
                Action brand (used for suspend / customer notice)
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                @foreach($brands as $brand)
                    <button
                        wire:click="assignBrand('{{ $brand->id }}')"
                        class="btn btn-sm"
                        style="
                            {{ $case->brand_id === $brand->id
                                ? 'background:#2563eb; color:#fff; border-color:#2563eb;'
                                : 'background:#fff; color:#374151; border:1px solid #e5e7eb;'
                            }}
                        "
                    >
                        {{ $brand->name }}
                    </button>
                @endforeach
                @if($brands->isEmpty())
                    <span class="text-sm text-muted">No brands configured. <a href="/admin/brands" style="color:#2563eb;">Add brands</a></span>
                @endif
            </div>
            @if($case->received_via_brand_id)
                <div style="margin-top:10px; padding-top:8px; border-top:1px solid #f1f5f9; font-size:11px; color:#6b7280;">
                    Received via <strong style="color:#374151;">{{ $case->receivedViaBrand?->name ?? '—' }}</strong>
                    — replies default to this inbox.
                </div>
            @endif
        </div>

        {{-- AI Summary --}}
        @if($case->ai_summary)
        <div class="card">
            <div class="card-title" style="margin-bottom:0.5rem;">AI Summary</div>
            <div class="text-sm">{{ $case->ai_summary }}</div>
        </div>
        @endif

        {{-- Threat Intelligence --}}
        @if($enrichment['abuseipdb'] || $enrichment['virustotal'] || $enrichment['shodan'] || ($enrichment['dnsbl'] && $enrichment['dnsbl']['listed']))
        <div class="card">
            <div class="card-header">
                <div class="card-title">Threat Intelligence</div>
                @if($enrichment['threat_score'])
                    <span class="badge badge-{{ $enrichment['threat_score'] > 60 ? 'critical' : ($enrichment['threat_score'] > 30 ? 'high' : 'low') }}">
                        Threat Score: {{ $enrichment['threat_score'] }}%
                    </span>
                @endif
            </div>

            <div class="intel-grid">
                {{-- AbuseIPDB --}}
                @if($enrichment['abuseipdb'])
                    <div class="intel-card">
                        <div class="intel-card-title">AbuseIPDB</div>
                        <div class="intel-stat" style="color:{{ ($enrichment['abuseipdb']['score'] ?? 0) > 50 ? '#dc2626' : (($enrichment['abuseipdb']['score'] ?? 0) > 0 ? '#f59e0b' : '#10b981') }};">
                            {{ $enrichment['abuseipdb']['score'] ?? 0 }}%
                        </div>
                        <div class="text-sm text-muted" style="margin-top:4px;">
                            {{ $enrichment['abuseipdb']['total_reports'] ?? 0 }} reports
                            @if($enrichment['abuseipdb']['country'] ?? null)
                                &middot; {{ $enrichment['abuseipdb']['country'] }}
                            @endif
                        </div>
                        @if($enrichment['abuseipdb']['isp'] ?? null)
                            <div class="text-sm text-muted">ISP: {{ $enrichment['abuseipdb']['isp'] }}</div>
                        @endif
                        @if($enrichment['abuseipdb']['last_reported'] ?? null)
                            <div class="text-sm text-muted">Last: {{ $enrichment['abuseipdb']['last_reported'] }}</div>
                        @endif
                    </div>
                @endif

                {{-- VirusTotal --}}
                @if($enrichment['virustotal'])
                    <div class="intel-card">
                        <div class="intel-card-title">VirusTotal</div>
                        <div style="display:flex; gap:12px; align-items:baseline;">
                            <div>
                                <div class="intel-stat" style="color:#dc2626;">{{ $enrichment['virustotal']['malicious'] ?? 0 }}</div>
                                <div class="text-sm text-muted">Malicious</div>
                            </div>
                            <div>
                                <div class="intel-stat" style="color:#f59e0b;">{{ $enrichment['virustotal']['suspicious'] ?? 0 }}</div>
                                <div class="text-sm text-muted">Suspicious</div>
                            </div>
                            <div>
                                <div class="intel-stat" style="color:#10b981;">{{ $enrichment['virustotal']['harmless'] ?? 0 }}</div>
                                <div class="text-sm text-muted">Clean</div>
                            </div>
                        </div>
                        @if($enrichment['virustotal']['as_owner'] ?? null)
                            <div class="text-sm text-muted" style="margin-top:4px;">AS: {{ $enrichment['virustotal']['as_owner'] }}</div>
                        @endif
                    </div>
                @endif

                {{-- Shodan --}}
                @if($enrichment['shodan'])
                    <div class="intel-card">
                        <div class="intel-card-title">Shodan</div>
                        @if(! empty($enrichment['shodan']['ports']))
                            <div class="text-sm"><strong>Ports:</strong> {{ implode(', ', array_slice($enrichment['shodan']['ports'], 0, 12)) }}{{ count($enrichment['shodan']['ports']) > 12 ? '...' : '' }}</div>
                        @endif
                        @if(! empty($enrichment['shodan']['vulns']))
                            <div style="margin-top:4px;">
                                <span class="badge badge-critical">{{ count($enrichment['shodan']['vulns']) }} vulns</span>
                                <div class="text-sm text-muted" style="margin-top:2px;">{{ implode(', ', array_slice($enrichment['shodan']['vulns'], 0, 5)) }}</div>
                            </div>
                        @endif
                        @if($enrichment['shodan']['organization'] ?? null)
                            <div class="text-sm text-muted" style="margin-top:4px;">Org: {{ $enrichment['shodan']['organization'] }}</div>
                        @endif
                        @if($enrichment['shodan']['os'] ?? null)
                            <div class="text-sm text-muted">OS: {{ $enrichment['shodan']['os'] }}</div>
                        @endif
                    </div>
                @endif

                {{-- DNSBL --}}
                @if($enrichment['dnsbl'] && $enrichment['dnsbl']['listed'])
                    <div class="intel-card" style="border-color:#fecaca;">
                        <div class="intel-card-title" style="color:#dc2626;">DNSBL Blacklists</div>
                        <div class="intel-stat" style="color:#dc2626;">{{ $enrichment['dnsbl']['count'] }}</div>
                        <div class="text-sm text-muted" style="margin-top:4px;">Listed on:</div>
                        @foreach($enrichment['dnsbl']['lists'] as $list)
                            <div class="text-sm" style="color:#991b1b;">{{ $list }}</div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Attachments (aggregated from all reports) --}}
        @if($attachments->isNotEmpty())
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Attachments ({{ $attachments->count() }})</div>
            <div style="display:flex; flex-direction:column; gap:6px;">
                @foreach($attachments as $att)
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:6px 10px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; font-size:12px;">
                        <div style="display:flex; align-items:center; gap:6px; min-width:0;">
                            <span style="color:#2563eb;">&darr;</span>
                            <a href="{{ route('admin.reports.attachments.download', ['report' => $att['report_id'], 'index' => $att['index']]) }}"
                                style="color:#2563eb; text-decoration:none; word-break:break-all;"
                                title="{{ $att['path'] }}">
                                <code style="background:none; padding:0;">{{ $att['filename'] }}</code>
                            </a>
                        </div>
                        <div class="text-muted" style="font-size:11px; white-space:nowrap;">
                            @if($att['reporter']) {{ $att['reporter'] }} &middot; @endif
                            {{ \Carbon\Carbon::parse($att['reported_at'])->format('M j, g:ia') }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Reports --}}
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Reports ({{ $case->report_count }})</div>
            @foreach($reports as $report)
                <div style="padding:0.75rem 0; border-bottom:1px solid var(--border); font-size:0.8125rem;">
                    @include('partials.report-detail', ['report' => $report])
                </div>
            @endforeach
        </div>

        {{-- Reply Form --}}
        @if($showReplyForm)
        <div class="card" style="border:2px solid #2563eb;">
            <div class="card-header">
                <div class="card-title" style="color:#2563eb;">Send Reply</div>
                <button wire:click="cancelReply" class="btn btn-secondary btn-sm">Cancel</button>
            </div>

            @if(session('reply-error'))
                <div style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:8px 12px; border-radius:6px; margin-bottom:12px; font-size:13px;">{{ session('reply-error') }}</div>
            @endif

            <form wire:submit="sendReply">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label for="reply-to-input" style="font-size:11px; font-weight:500;">To *</label>
                        <input id="reply-to-input" type="email" wire:model="replyTo" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        @error('replyTo') <div style="color:#dc2626; font-size:11px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="reply-brand-input" style="font-size:11px; font-weight:500;">Brand / From</label>
                        <select id="reply-brand-input" wire:model="replyBrandId" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <option value="">Default</option>
                            @foreach($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }} ({{ $brand->from_email }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label for="reply-subject-input" style="font-size:11px; font-weight:500;">Subject *</label>
                    <input id="reply-subject-input" type="text" wire:model="replySubject" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                </div>
                <div style="margin-bottom:12px;">
                    <label for="reply-body-input" style="font-size:11px; font-weight:500;">Message *</label>
                    <textarea id="reply-body-input" wire:model="replyBody" rows="8" style="width:100%; padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; resize:vertical;"></textarea>
                    @error('replyBody') <div style="color:#dc2626; font-size:11px;">{{ $message }}</div> @enderror
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <span wire:loading.remove wire:target="sendReply">Send Email</span>
                        <span wire:loading wire:target="sendReply">Sending...</span>
                    </button>
                    <button type="button" wire:click="cancelReply" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
        @endif

        @if(session('reply-success'))
            <div style="background:#d1fae5; border:1px solid #6ee7b7; color:#065f46; padding:8px 12px; border-radius:6px; margin-bottom:12px; font-size:13px;">{{ session('reply-success') }}</div>
        @endif

        {{-- Email Conversation --}}
        @if($conversation->isNotEmpty())
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Email Conversation ({{ $conversation->count() }})</div>
            @foreach($conversation as $msg)
                <div style="padding:10px 12px; margin-bottom:8px; border-radius:8px; font-size:13px;
                    {{ $msg['type'] === 'sent'
                        ? 'background:#eff6ff; border:1px solid #bfdbfe;'
                        : 'background:#f3f4f6; border:1px solid #e5e7eb;' }}">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            @if($msg['type'] === 'sent')
                                <span style="color:#2563eb; font-size:14px;" title="Sent">&rarr;</span>
                                <strong style="color:#1d4ed8;">{{ $msg['from'] }}</strong>
                                <span class="text-muted">&rarr; {{ $msg['to'] ?? '' }}</span>
                            @else
                                <span style="color:#6b7280; font-size:14px;" title="Received">&larr;</span>
                                <strong>{{ $msg['from_name'] ? $msg['from_name'] . ' <' . $msg['from'] . '>' : $msg['from'] }}</strong>
                            @endif
                            @if(isset($msg['status']))
                                <span class="badge {{ $msg['status'] === 'sent' ? 'badge-success' : ($msg['status'] === 'queued' ? 'badge-pending' : ($msg['status'] === 'failed' ? 'badge-failed' : 'badge-open')) }}">{{ $msg['status'] }}</span>
                            @endif
                            @if(($msg['transport'] ?? null) === 'smtp_fallback')
                                <span class="badge badge-pending" title="Mandrill was unavailable — delivered through the brand's own SMTP mailbox">via brand SMTP</span>
                            @endif
                        </div>
                        <span class="text-muted" style="font-size:11px; white-space:nowrap;">{{ \Carbon\Carbon::parse($msg['date'])->format('M j, g:ia') }}</span>
                    </div>
                    @if($msg['subject'] ?? '')
                        <div style="font-size:12px; color:#374151; margin-bottom:4px;"><strong>Subject:</strong> {{ $msg['subject'] }}</div>
                    @endif
                    <details>
                        <summary style="cursor:pointer; color:#2563eb; font-size:12px;">View message</summary>
                        @if($msg['type'] === 'sent')
                            {{-- Render outbound HTML in a sandboxed iframe so any malformed
                                 or attacker-influenced markup can't execute scripts in admin context. --}}
                            <iframe sandbox srcdoc="{{ $msg['body'] }}" style="width:100%; min-height:200px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; margin-top:6px;"></iframe>
                        @else
                            <div style="margin-top:6px; padding:10px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; font-size:12px; word-break:break-word;">
                                {!! nl2br(e($msg['body'])) !!}
                            </div>
                        @endif
                    </details>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Sent Emails --}}
        @if($sentEmails->isNotEmpty())
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Sent Emails ({{ $sentEmails->count() }})</div>
            @foreach($sentEmails as $email)
                <div style="padding:8px 0; border-bottom:1px solid #e5e7eb; font-size:13px;">
                    <div class="flex" style="justify-content:space-between;">
                        <div>
                            <strong>To:</strong> {{ $email->to_email }}
                            <span class="badge {{ $email->status === 'sent' ? 'badge-success' : ($email->status === 'queued' ? 'badge-pending' : ($email->status === 'failed' ? 'badge-failed' : 'badge-open')) }}">{{ $email->status }}</span>
                            @if(($email->metadata['transport'] ?? null) === 'smtp_fallback')
                                <span class="badge badge-pending" title="Mandrill was unavailable — delivered through the brand's own SMTP mailbox">via brand SMTP</span>
                            @endif
                            @if($email->brand)
                                <span class="badge badge-investigating">{{ $email->brand->name }}</span>
                            @endif
                        </div>
                        <span class="text-muted">{{ $email->created_at->format('M j, g:ia') }}</span>
                    </div>
                    <div class="text-muted" style="margin-top:2px;">{{ $email->subject }}</div>
                    <details style="margin-top:4px;">
                        <summary style="cursor:pointer; color:#2563eb; font-size:12px;">View message</summary>
                        <iframe sandbox srcdoc="{{ $email->body_html }}" style="width:100%; min-height:200px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; margin-top:6px;"></iframe>
                    </details>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Timeline --}}
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Activity Timeline</div>
            <div class="timeline">
                @forelse($actions as $action)
                    <div class="timeline-item">
                        <div class="timeline-time">{{ $action->created_at->format('M j, g:ia') }}</div>
                        <div class="timeline-content">
                            <strong>{{ ucfirst(str_replace('_', ' ', $action->action_type->value)) }}</strong>
                            @if($action->note)
                                <div class="text-muted">{{ $action->note }}</div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-muted text-sm" style="padding:8px 0;">No activity yet.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Right column: Actions + AI Panel --}}
    <div>
        {{-- Status change --}}
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Actions</div>
            <div style="margin-bottom:0.75rem;">
                <label style="font-size:0.75rem;">Change Status</label>
                <div class="flex gap-2">
                    <select wire:model="newStatus" style="flex:1; padding:0.375rem 0.5rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                        @foreach($statuses as $s)
                            <option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>
                        @endforeach
                    </select>
                    <button wire:click="updateStatus" class="btn btn-primary btn-sm">Update</button>
                </div>
            </div>

            {{-- Close case --}}
            <div style="margin-bottom:0.75rem; padding-top:0.75rem; border-top:1px solid #e5e7eb;">
                @if($case->status->value === 'closed')
                    <div class="text-muted text-sm" style="font-size:12px;">
                        Case closed {{ $case->closed_at?->diffForHumans() }}
                        @if(($case->metadata['closure_reason_label'] ?? null))
                            — <strong>{{ $case->metadata['closure_reason_label'] }}</strong>
                        @endif
                        @if(($case->metadata['closure_note'] ?? null))
                            <div style="margin-top:4px; color:#374151;">{{ $case->metadata['closure_note'] }}</div>
                        @endif
                    </div>
                @elseif(! $showCloseForm)
                    <button wire:click="openCloseForm" class="btn btn-secondary btn-sm" style="width:100%;">Close case…</button>
                @else
                    @if(session('close-error'))
                        <div style="background:#fee2e2; color:#991b1b; padding:6px 10px; border-radius:4px; font-size:12px; margin-bottom:8px;">{{ session('close-error') }}</div>
                    @endif
                    <label style="font-size:0.75rem;">Close reason</label>
                    <select wire:model="closeReason" style="width:100%; padding:0.375rem 0.5rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; margin-bottom:6px;">
                        <option value="">Select a reason…</option>
                        @foreach($closeReasons as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <textarea
                        wire:model="closeNote"
                        placeholder="Optional note (required when reason is &quot;Other&quot;)"
                        style="min-height:48px; font-size:0.8125rem; width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:6px;"></textarea>
                    <div style="display:flex; gap:8px;">
                        <button wire:click="closeCase" class="btn btn-danger btn-sm" style="flex:1;" onclick="return confirm('Close this case? You can still view it afterwards but it will be marked as closed.')">Close case</button>
                        <button wire:click="cancelClose" class="btn btn-secondary btn-sm" style="flex:1;">Cancel</button>
                    </div>
                @endif
                @if(session('close-success'))
                    <div style="background:#d1fae5; color:#065f46; padding:6px 10px; border-radius:4px; font-size:12px; margin-top:8px;">{{ session('close-success') }}</div>
                @endif
            </div>

            {{-- Reply button --}}
            <div style="margin-bottom:0.75rem; padding-top:0.75rem; border-top:1px solid #e5e7eb;">
                <button wire:click="openReply" class="btn btn-primary" style="width:100%; justify-content:center;">Reply via Email</button>
            </div>

            {{-- Add note --}}
            <div x-data="{ note: $wire.entangle('newNote') }">
                <label for="note-textarea" style="font-size:0.75rem;">Add Note</label>
                <textarea id="note-textarea"
                    x-model="note"
                    x-on:keydown.cmd.enter.prevent="$wire.addNote()"
                    x-on:keydown.ctrl.enter.prevent="$wire.addNote()"
                    style="min-height:60px; font-size:0.8125rem; width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px;"
                    placeholder="Internal note... (⌘/Ctrl + Enter to save)"></textarea>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:6px;">
                    <span class="text-muted" style="font-size:11px;" x-text="(note || '').length + ' chars'"></span>
                    @if(session('note-success'))
                        <span class="text-success text-sm">{{ session('note-success') }}</span>
                    @endif
                </div>
                <button wire:click="addNote" wire:loading.attr="disabled" wire:target="addNote" class="btn btn-secondary btn-sm mt-4">
                    <span wire:loading.remove wire:target="addNote">Add Note</span>
                    <span wire:loading wire:target="addNote">Saving…</span>
                </button>
            </div>
        </div>

        {{-- Merge Case --}}
        <div class="card" style="margin-top:12px;">
            <button wire:click="openMergeForm" class="btn btn-secondary btn-sm" style="width:100%;">Merge Into Another Case</button>

            @if($showMergeForm)
                <div style="margin-top:12px;">
                    @if(session('merge-error'))
                        <div style="background:#fee2e2; color:#991b1b; padding:6px 10px; border-radius:4px; font-size:12px; margin-bottom:8px;">{{ session('merge-error') }}</div>
                    @endif
                    @if(session('merge-success'))
                        <div style="background:#d1fae5; color:#065f46; padding:6px 10px; border-radius:4px; font-size:12px; margin-bottom:8px;">{{ session('merge-success') }}</div>
                    @endif
                    <input type="text" wire:model="mergeTargetNumber" placeholder="ABU-2026-XXXXX" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; margin-bottom:8px;">
                    <div style="display:flex; gap:8px;">
                        <button wire:click="mergeIntoCase" class="btn btn-primary btn-sm" style="flex:1;" onclick="return confirm('Merge this case into the target? This cannot be undone.')">Merge</button>
                        <button wire:click="cancelMerge" class="btn btn-secondary btn-sm" style="flex:1;">Cancel</button>
                    </div>
                </div>
            @endif
        </div>

        {{-- URL Takedown — shown when the case has reports targeting a URL
             whose host is handled by the brand's url_takedown config. One
             click runs lookup-by-short → DELETE on the upstream API. --}}
        @if(! empty($takedownUrls))
            <div class="card" style="margin-bottom:16px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <div class="card-title" style="margin:0;">URL Takedown</div>
                    <span class="badge badge-investigating">{{ count($takedownUrls) }}</span>
                </div>
                <div class="text-sm text-muted" style="margin-bottom:8px;">
                    The brand's Custom HTTP API can delete these URLs upstream. Lookup → DELETE in one click.
                </div>
                @if(session('infra-success'))
                    <div style="background:#d1fae5; padding:6px 10px; border-radius:4px; font-size:12px; color:#065f46; margin-bottom:8px;">{{ session('infra-success') }}</div>
                @endif
                @if(session('infra-error'))
                    <div style="background:#fee2e2; padding:6px 10px; border-radius:4px; font-size:12px; color:#991b1b; margin-bottom:8px;">{{ session('infra-error') }}</div>
                @endif
                <div style="display:flex; flex-direction:column; gap:6px;">
                    @foreach($takedownUrls as $tdUrl)
                        <div style="display:flex; align-items:center; gap:8px; padding:6px 10px; background:#fff; border:1px solid #e5e7eb; border-radius:6px;">
                            <span style="flex:1; font-family:monospace; font-size:12px; word-break:break-all;">{{ $tdUrl }}</span>
                            <button type="button"
                                wire:click="takedownUrl(@js($tdUrl))"
                                wire:loading.attr="disabled"
                                wire:target="takedownUrl(@js($tdUrl))"
                                onclick="return confirm('Delete this URL upstream? This is irreversible.')"
                                class="btn btn-danger btn-sm">
                                <span wire:loading.remove wire:target="takedownUrl(@js($tdUrl))">Takedown</span>
                                <span wire:loading wire:target="takedownUrl(@js($tdUrl))">Deleting...</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Infrastructure Panel — provider-agnostic. The Livewire component
             flattens whatever provider returned a ServiceRecord into the
             $infraData array; the view never branches on provider type,
             only on the capabilities flag and presence of fields. --}}
        @if(is_array($infraData) && empty($infraData['errors']) && ! empty($infraData['service_id']))
        @php
            $caps = $infraData['capabilities'] ?? [];
            $canSuspend = in_array('suspend', $caps, true);
            $canUnsuspend = in_array('unsuspend', $caps, true);
            $canOpenTicket = in_array('open_ticket', $caps, true) && ! empty($infraData['client_id']);
            $statusBadge = match ($infraData['status'] ?? 'unknown') {
                'active' => 'badge-resolved',
                'suspended', 'stopped' => 'badge-critical',
                'terminated' => 'badge-closed',
                default => 'badge-investigating',
            };
        @endphp
        <div class="card" style="border:1px solid #93c5fd; background:#eff6ff;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <div class="card-title" style="color:#1d4ed8;">
                    Infrastructure
                    @if($infraData['provider'] ?? null)
                        <span class="text-muted" style="font-size:11px; font-weight:normal;">via {{ $infraData['provider'] }}</span>
                    @endif
                </div>
                <button wire:click="refreshInfrastructure" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="refreshInfrastructure">
                    <span wire:loading.remove wire:target="refreshInfrastructure">Refresh</span>
                    <span wire:loading wire:target="refreshInfrastructure">Loading...</span>
                </button>
            </div>

            @if(session('infra-success'))
                <div style="background:#d1fae5; padding:6px 10px; border-radius:4px; font-size:12px; color:#065f46; margin-bottom:10px;">{{ session('infra-success') }}</div>
            @endif
            @if(session('infra-error'))
                <div style="background:#fee2e2; padding:6px 10px; border-radius:4px; font-size:12px; color:#991b1b; margin-bottom:10px;">{{ session('infra-error') }}</div>
            @endif

            <div style="background:#fff; border:1px solid var(--border); border-radius:6px; padding:10px; margin-bottom:8px;">
                <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#2563eb; margin-bottom:6px;">Service</div>

                @if($infraServicePredatesAbuse)
                    <div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:6px 8px; border-radius:4px; font-size:11px; margin-bottom:6px;">
                        <strong>Not linked to case:</strong>
                        service registered <strong>{{ $infraData['registered_at'] ?? 'after abuse' }}</strong>,
                        after the abuse occurred on
                        <strong>{{ optional($case->effective_abuse_occurred_at)->format('Y-m-d') }}</strong>.
                        The IP belonged to a different customer at the time.
                    </div>
                @endif

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:4px 12px; font-size:12px;">
                    <div><strong>Service ID:</strong>
                        @if($infraData['admin_url'] ?? null)
                            <a href="{{ $infraData['admin_url'] }}" target="_blank" style="color:#2563eb;">{{ $infraData['service_id'] }}</a>
                        @else
                            {{ $infraData['service_id'] }}
                        @endif
                    </div>
                    <div><strong>Client ID:</strong> {{ $infraData['client_id'] ?? '-' }}</div>
                    @if($infraData['product'] ?? null)
                        <div><strong>Product:</strong> {{ $infraData['product'] }} <span class="text-muted">{{ $infraData['product_group'] ?? '' }}</span></div>
                    @endif
                    <div><strong>Status:</strong>
                        <span class="badge {{ $statusBadge }}">{{ $infraData['status_label'] ?? ucfirst($infraData['status']) }}</span>
                    </div>
                    <div><strong>Hostname:</strong> {{ $infraData['hostname'] ?? '-' }}</div>
                    <div><strong>IP:</strong> {{ $infraData['ip'] ?? '-' }}</div>
                    @if($infraData['user_email'] ?? null)
                        <div><strong>User Email:</strong> {{ $infraData['user_email'] }}</div>
                    @endif
                    @if($infraData['server_name'] ?? null)
                        <div><strong>Server:</strong> {{ $infraData['server_name'] }} {{ ($infraData['server_ip'] ?? null) ? "({$infraData['server_ip']})" : '' }}</div>
                    @endif
                    @if($infraData['order_number'] ?? null)
                        <div><strong>Order #:</strong> {{ $infraData['order_number'] }}</div>
                    @endif
                    @if(($infraData['billing_cycle'] ?? null) || ($infraData['amount'] ?? null))
                        <div><strong>Billing:</strong> {{ $infraData['billing_cycle'] ?? '-' }} — {{ $infraData['amount'] ?? '-' }}</div>
                    @endif
                    @if($infraData['next_due_at'] ?? null)
                        <div><strong>Next Due:</strong> {{ $infraData['next_due_at'] }}</div>
                    @endif
                    @if($infraData['payment_method'] ?? null)
                        <div><strong>Payment:</strong> {{ $infraData['payment_method'] }}</div>
                    @endif
                    @if($infraData['registered_at'] ?? null)
                        <div><strong>Registered:</strong> {{ $infraData['registered_at'] }}</div>
                    @endif
                </div>

                @if(! empty($infraData['raw_fields']))
                    <details style="margin-top:6px;">
                        <summary style="cursor:pointer; font-size:11px; color:#2563eb;">Additional Fields</summary>
                        <div style="margin-top:4px; font-size:11px;">
                            @foreach($infraData['raw_fields'] as $name => $value)
                                @if($value !== null && $value !== '')
                                    <div><strong>{{ $name }}:</strong> {{ is_scalar($value) ? $value : json_encode($value) }}</div>
                                @endif
                            @endforeach
                        </div>
                    </details>
                @endif

                @if($infraData['suspension_reason'] ?? null)
                    <div style="margin-top:6px; font-size:12px; color:#dc2626;">
                        <strong>Suspension Reason:</strong> {{ $infraData['suspension_reason'] }}
                    </div>
                @endif

                {{-- Action / customer link strip --}}
                <div style="margin-top:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    @if($case->customer_id && $existingCustomer && $case->customer_id === $existingCustomer->id)
                        <span class="badge badge-resolved">Linked</span>
                        <a href="/admin/customers/{{ $existingCustomer->id }}" style="color:#2563eb; font-size:12px;">View Customer</a>
                    @elseif($infraServicePredatesAbuse)
                        <span class="badge badge-critical" title="Service registered after the abuse occurred — current customer didn't own this IP at the time.">
                            Service registered after abuse — not linked
                        </span>
                    @else
                        <button wire:click="addCustomerFromInfrastructure" class="btn btn-primary btn-sm"
                            wire:loading.attr="disabled" wire:target="addCustomerFromInfrastructure">
                            <span wire:loading.remove wire:target="addCustomerFromInfrastructure">
                                {{ $existingCustomer ? 'Link Customer to Case' : 'Add Customer from Infra' }}
                            </span>
                            <span wire:loading wire:target="addCustomerFromInfrastructure">Saving...</span>
                        </button>
                        @if($existingCustomer)
                            <a href="/admin/customers/{{ $existingCustomer->id }}" style="color:#2563eb; font-size:12px;">Existing: {{ $existingCustomer->email }}</a>
                        @endif
                    @endif

                    {{-- Action buttons are hidden when the abuse predates the
                         service's registration: the IP belonged to a different
                         customer at the time, so the current owner must not be
                         actioned. --}}
                    @if(! $infraServicePredatesAbuse)
                    @if(($infraData['status'] ?? '') === 'active' && $canSuspend)
                        <button wire:click="suspendWhmcsService" class="btn btn-danger btn-sm"
                            onclick="return confirm('Suspend service #{{ $infraData['service_id'] }}?')"
                            wire:loading.attr="disabled" wire:target="suspendWhmcsService">
                            <span wire:loading.remove wire:target="suspendWhmcsService">Suspend Service</span>
                            <span wire:loading wire:target="suspendWhmcsService">Suspending...</span>
                        </button>
                    @endif

                    {{-- Reverse the suspend once the customer has remediated.
                         Only shown when the service status returned by the
                         provider is "suspended" — avoids the "unsuspend an
                         already-active service" footgun. --}}
                    @if(in_array($infraData['status'] ?? '', ['suspended', 'stopped'], true) && $canUnsuspend)
                        <button wire:click="unsuspendService" class="btn btn-secondary btn-sm"
                            onclick="return confirm('Unsuspend service #{{ $infraData['service_id'] }}? Confirm the customer has remediated the abuse before lifting the suspension.')"
                            wire:loading.attr="disabled" wire:target="unsuspendService"
                            title="Reverse the suspend. Use after the customer has responded to the abuse ticket and remediated the issue.">
                            <span wire:loading.remove wire:target="unsuspendService">Unsuspend Service</span>
                            <span wire:loading wire:target="unsuspendService">Unsuspending...</span>
                        </button>
                    @endif

                    {{-- Manual notify / suspend-and-notify, for cases where the
                         abuse score didn't trip the auto-suspend rule but the
                         agent still wants to action. Both shown only when the
                         provider supports OpenTicket and we have a client id. --}}
                    @if($canOpenTicket)
                        <button wire:click="openClientTicket" class="btn btn-secondary btn-sm"
                            onclick="return confirm('Open a warning ticket to client #{{ $infraData['client_id'] }} (no suspend)?')"
                            wire:loading.attr="disabled" wire:target="openClientTicket"
                            title="Open a WHMCS support ticket to the client without suspending the service. Use for medium-severity reports.">
                            <span wire:loading.remove wire:target="openClientTicket">Notify Client (Ticket Only)</span>
                            <span wire:loading wire:target="openClientTicket">Opening ticket...</span>
                        </button>
                    @endif

                    @if(($infraData['status'] ?? '') === 'active' && $canSuspend && $canOpenTicket)
                        <button wire:click="suspendAndNotifyClient" class="btn btn-danger btn-sm"
                            onclick="return confirm('Suspend service #{{ $infraData['service_id'] }} AND open a ticket to client #{{ $infraData['client_id'] }}?')"
                            wire:loading.attr="disabled" wire:target="suspendAndNotifyClient"
                            title="Suspend the service and open a WHMCS ticket to the client in one click — manual equivalent of the auto-suspend rule.">
                            <span wire:loading.remove wire:target="suspendAndNotifyClient">Suspend + Open Ticket</span>
                            <span wire:loading wire:target="suspendAndNotifyClient">Suspending...</span>
                        </button>
                    @endif
                    @endif

                    @if($infraData['brand_name'] ?? null)
                        <span class="text-muted" style="font-size:11px;">Brand: <strong>{{ $infraData['brand_name'] }}</strong></span>
                    @endif
                </div>
            </div>
        </div>
        @elseif($case->target_ip)
        <div class="card" style="border:1px solid #e5e7eb;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div class="card-title">Infrastructure</div>
                @if(is_array($infraData) && ! empty($infraData['errors']))
                    <span class="text-sm" style="color:#dc2626;">{{ $infraData['errors'][0] ?? 'lookup failed' }}</span>
                @endif
                <button wire:click="loadInfrastructure" class="btn btn-secondary btn-sm"
                    wire:loading.attr="disabled" wire:target="loadInfrastructure">
                    <span wire:loading.remove wire:target="loadInfrastructure">{{ $infraData ? 'Retry' : 'Load' }}</span>
                    <span wire:loading wire:target="loadInfrastructure">Loading...</span>
                </button>
            </div>
            @if(is_array($infraData) && empty($infraData['errors']) && empty($infraData['service_id']))
                <div class="text-sm text-muted" style="margin-top:6px;">No service found for this IP under the brand's provider.</div>
            @endif
        </div>
        @endif

        {{-- AI Panel --}}
        <div class="ai-panel">
            <div class="ai-panel-title">AI Assistant</div>

            @if(session('ai-message'))
                <div style="background:#ede9fe; padding:0.5rem 0.75rem; border-radius:6px; font-size:0.75rem; margin-bottom:0.75rem; color:#5b21b6;">
                    {{ session('ai-message') }}
                </div>
            @endif

            <div style="display:grid; gap:0.5rem;">
                <button wire:click="requestAiRescore" wire:loading.attr="disabled" wire:target="requestAiRescore" class="btn btn-secondary btn-sm" style="width:100%; justify-content:center;">
                    <span wire:loading.remove wire:target="requestAiRescore">Re-score with AI</span>
                    <span wire:loading wire:target="requestAiRescore">Working…</span>
                </button>
                <button wire:click="requestAiSummary" wire:loading.attr="disabled" wire:target="requestAiSummary" class="btn btn-secondary btn-sm" style="width:100%; justify-content:center;">
                    <span wire:loading.remove wire:target="requestAiSummary">Generate Summary</span>
                    <span wire:loading wire:target="requestAiSummary">Working…</span>
                </button>
                <button wire:click="requestAiReporterDraft" wire:loading.attr="disabled" wire:target="requestAiReporterDraft" class="btn btn-secondary btn-sm" style="width:100%; justify-content:center;">
                    <span wire:loading.remove wire:target="requestAiReporterDraft">Draft Reporter Reply</span>
                    <span wire:loading wire:target="requestAiReporterDraft">Working…</span>
                </button>
                <button wire:click="requestAiCustomerDraft" wire:loading.attr="disabled" wire:target="requestAiCustomerDraft" class="btn btn-secondary btn-sm" style="width:100%; justify-content:center;">
                    <span wire:loading.remove wire:target="requestAiCustomerDraft">Draft Customer Notice</span>
                    <span wire:loading wire:target="requestAiCustomerDraft">Working…</span>
                </button>
            </div>

            {{-- AI Drafts --}}
            @if($aiDrafts->isNotEmpty())
                <div style="margin-top:1rem;">
                    <div style="font-size:0.75rem; font-weight:500; color:#5b21b6; margin-bottom:0.5rem;">Recent Drafts</div>
                    @foreach($aiDrafts as $draft)
                        <div class="ai-draft">
                            <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.6875rem; color:var(--text-muted); margin-bottom:0.375rem; gap:6px;">
                                <span>{{ $draft->payload['draft_type'] ?? 'draft' }} &middot; @reldate($draft->created_at)</span>
                                <div style="display:flex; gap:6px;">
                                    @if(isset($draft->payload['draft_body']))
                                        <button wire:click="useAiDraft('{{ $draft->id }}')"
                                            onclick="if (document.getElementById('reply-body-input')?.value?.trim()) { return confirm('This will overwrite the reply you have already typed. Continue?'); }"
                                            class="btn btn-primary btn-sm" style="font-size:10px; padding:2px 8px;">Use as Reply</button>
                                    @endif
                                    <button wire:click="dismissAiDraft('{{ $draft->id }}')"
                                        onclick="return confirm('Dismiss this AI draft? The audit log entry stays.')"
                                        aria-label="Dismiss draft"
                                        title="Dismiss draft"
                                        style="background:none; border:none; color:var(--text-muted); font-size:14px; line-height:1; cursor:pointer; padding:0 4px;">&times;</button>
                                </div>
                            </div>
                            {{ $draft->payload['draft_body'] ?? $draft->note }}
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
