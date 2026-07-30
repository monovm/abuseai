<div>
    <x-flash-messages />
    @if(session('info'))
        <div style="background:#dbeafe; border:1px solid #93c5fd; color:#1e40af; padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem; font-size:0.8125rem;">{{ session('info') }}</div>
    @endif

    {{-- Connection Switcher --}}
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div>
                <div class="card-title" style="margin-bottom:4px;">
                    @if($connectionName)
                        {{ $connectionName }}
                        @if($brandName)
                            <span class="badge badge-investigating" style="margin-left:6px;">{{ $brandName }}</span>
                        @endif
                    @else
                        Email Inbox
                    @endif
                </div>
                <div class="text-sm text-muted">{{ $username ?: 'No connection selected' }} — {{ $host ? "{$host}:{$port}" : '' }}</div>
            </div>
            <div class="flex gap-2">
                <button wire:click="testConnection" class="btn btn-secondary btn-sm" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="testConnection">Test</span>
                    <span wire:loading wire:target="testConnection">...</span>
                </button>
                <button wire:click="fetchInbox" class="btn btn-primary btn-sm" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="fetchInbox">Fetch Inbox</span>
                    <span wire:loading wire:target="fetchInbox">Loading...</span>
                </button>
            </div>
        </div>

        @if(count($allConnections) > 1)
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                @foreach($allConnections as $conn)
                    <button wire:click="switchConnection('{{ $conn['id'] }}')"
                        class="btn {{ $connectionId === $conn['id'] ? 'btn-primary' : 'btn-secondary' }} btn-sm">
                        {{ $conn['name'] }}
                        @if($conn['brand']) <span style="opacity:0.7; margin-left:2px;">({{ $conn['brand'] }})</span> @endif
                    </button>
                @endforeach
            </div>
        @endif

        @if($protocol === 'imap' && count($folders) > 1)
            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; align-items:center;">
                <span style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em;">Folder:</span>
                @foreach($folders as $f)
                    <button wire:click="switchFolder('{{ $f }}')"
                        class="btn {{ $folder === $f ? 'btn-primary' : 'btn-secondary' }} btn-sm">
                        {{ $f }}
                    </button>
                @endforeach
            </div>
        @endif

        @if($connectionError)
            <div style="margin-top:8px; color:#dc2626; font-size:13px;">{{ $connectionError }}</div>
        @endif

        @if(empty($allConnections) && ! $host)
            <div class="text-sm text-muted" style="margin-top:8px;">
                No email connections configured. <a href="/admin/brands" style="color:#2563eb;">Set up connections in Brands & Email</a>.
            </div>
        @endif
    </div>

    @if(! empty($emails))
        {{-- Bulk actions --}}
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
            <div class="text-sm text-muted">{{ count($emails) }} emails in inbox</div>
            <div class="flex gap-2">
                @php
                    $skippableCount = collect($emails)->filter(fn ($e) => ! empty($e['skip_reason']))->count();
                    $importableCount = count($emails) - $skippableCount;
                @endphp
                <button wire:click="importAllAsReports" class="btn btn-primary btn-sm"
                    onclick="return confirm('Import {{ $importableCount }} email(s) as abuse reports?{{ $skippableCount > 0 ? ' (' . $skippableCount . ' will be skipped due to filters)' : '' }}')">
                    Import All as Reports{{ $skippableCount > 0 ? ' (' . $importableCount . ')' : '' }}
                </button>
                <button wire:click="fetchInbox" class="btn btn-secondary btn-sm">Refresh</button>
            </div>
        </div>
    @endif

    {{-- On mobile, when an email is selected we hide the list and show only
         the preview so it doesn't end up scrolled off-screen below a long inbox. --}}
    <style>
        @media (max-width: 768px) {
            .email-list-pane.is-hidden-mobile { display: none; }
            .email-back-mobile { display: inline-flex !important; }
        }
        .email-back-mobile { display: none; }
    </style>
    <div class="resp-grid-2" style="display:grid; grid-template-columns:{{ $selectedEmail ? '1fr 1fr' : '1fr' }}; gap:1rem;">
        {{-- Email List --}}
        @if(! empty($emails))
            <div class="card email-list-pane {{ $selectedEmail ? 'is-hidden-mobile' : '' }}" style="padding:0; overflow:hidden;">
                <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>From</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($emails as $email)
                            <tr style="{{ $selectedEmail && $selectedEmail['id'] === $email['id'] ? 'background:#eff6ff;' : '' }}{{ ! empty($email['skip_reason']) ? 'opacity:0.5;' : '' }}">
                                <td class="text-muted">{{ $email['id'] }}</td>
                                <td class="text-sm" title="{{ $email['from'] }}" style="max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $email['from'] }}</td>
                                <td class="text-sm" title="{{ $email['subject'] ?: '(no subject)' }}" style="max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    <strong>{{ $email['subject'] ?: '(no subject)' }}</strong>
                                    @if(! empty($email['skip_reason']))
                                        <div><span class="badge badge-closed" style="font-size:9px;">{{ $email['skip_reason'] }}</span></div>
                                    @endif
                                </td>
                                <td class="text-sm text-muted" style="white-space:nowrap;">{{ \Illuminate\Support\Str::limit($email['date'], 20) }}</td>
                                <td>
                                    <div class="flex gap-2">
                                        <button wire:click="viewEmail({{ $email['id'] }})" class="btn btn-secondary btn-sm"
                                            wire:loading.attr="disabled" wire:target="viewEmail({{ $email['id'] }})">
                                            <span wire:loading.remove wire:target="viewEmail({{ $email['id'] }})">View</span>
                                            <span wire:loading wire:target="viewEmail({{ $email['id'] }})">...</span>
                                        </button>
                                        <button wire:click="importAsReport({{ $email['id'] }})" class="btn btn-primary btn-sm"
                                            wire:loading.attr="disabled" wire:target="importAsReport({{ $email['id'] }})">
                                            <span wire:loading.remove wire:target="importAsReport({{ $email['id'] }})">Import</span>
                                            <span wire:loading wire:target="importAsReport({{ $email['id'] }})">...</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @elseif($connected)
            <div class="card" style="text-align:center; color:var(--text-muted); padding:2rem;">
                Inbox is empty.
            </div>
        @endif

        {{-- Email Preview --}}
        @if($selectedEmail)
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:1rem; flex-wrap:wrap; gap:8px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button wire:click="closeEmail" class="btn btn-secondary btn-sm email-back-mobile" aria-label="Back to inbox">&larr; Back</button>
                        <div class="card-title">Email Preview</div>
                    </div>
                    <div class="flex gap-2">
                        <button wire:click="importAsReport({{ $selectedEmail['id'] }})" class="btn btn-primary btn-sm">Import as Report</button>
                        <button wire:click="deleteEmail({{ $selectedEmail['id'] }})" class="btn btn-danger btn-sm" onclick="return confirm('Delete this email from server? This removes it from the mailbox permanently.')">Delete</button>
                        <button wire:click="closeEmail" class="btn btn-secondary btn-sm">Close</button>
                    </div>
                </div>

                <div style="font-size:0.8125rem; margin-bottom:1rem;">
                    <div style="display:grid; grid-template-columns:auto 1fr; gap:0.25rem 0.75rem;">
                        <strong>From:</strong> <span>{{ $selectedEmail['from'] }}</span>
                        <strong>To:</strong> <span>{{ $selectedEmail['to'] }}</span>
                        <strong>Subject:</strong> <span>{{ $selectedEmail['subject'] }}</span>
                        <strong>Date:</strong> <span>{{ $selectedEmail['date'] }}</span>
                    </div>
                </div>

                <div style="border-top:1px solid var(--border); padding-top:1rem;">
                    <pre style="white-space:pre-wrap; word-wrap:break-word; font-size:0.8125rem; font-family:-apple-system, sans-serif; line-height:1.6; max-height:500px; overflow-y:auto; background:#f9fafb; padding:1rem; border-radius:6px;">{{ $selectedEmail['body'] }}</pre>
                </div>
            </div>
        @endif
    </div>
</div>
