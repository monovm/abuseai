<div style="position:relative;" wire:poll.60s="refresh" @click.outside="$wire.set('open', false)">
    <button type="button" wire:click="toggle"
        title="Notifications"
        aria-label="Notifications ({{ $unreadCount }} unread)"
        style="background:none; border:none; cursor:pointer; padding:6px 8px; display:flex; align-items:center; position:relative; color:#374151;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        @if($unreadCount > 0)
            <span aria-hidden="true" style="position:absolute; top:2px; right:2px; min-width:16px; height:16px; padding:0 4px; border-radius:999px; background:#dc2626; color:#fff; font-size:10px; font-weight:600; display:flex; align-items:center; justify-content:center; line-height:1;">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    @if($open)
        <div style="position:absolute; top:100%; right:0; margin-top:6px; width:360px; max-width:90vw; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.12); z-index:60; max-height:70vh; overflow:hidden; display:flex; flex-direction:column;">
            <div style="padding:10px 14px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <strong style="font-size:13px;">Notifications</strong>
                @if($unreadCount > 0)
                    <button type="button" wire:click="markAllRead" style="background:none; border:none; color:#2563eb; font-size:12px; cursor:pointer; padding:0;">Mark all read</button>
                @endif
            </div>

            <div style="overflow-y:auto;">
                @forelse($items as $note)
                    <button type="button" wire:click="markRead('{{ $note->id }}')"
                        style="width:100%; text-align:left; padding:10px 14px; border:none; border-bottom:1px solid #f3f4f6; cursor:pointer; background:{{ $note->read_at ? '#fff' : '#eff6ff' }}; display:block;">
                        <div style="display:flex; justify-content:space-between; gap:8px; align-items:baseline;">
                            <span style="font-size:12px; font-weight:600; color:#1f2937;">{{ $note->title }}</span>
                            <span style="font-size:10px; color:#9ca3af; white-space:nowrap;">{{ $note->created_at->diffForHumans(null, true) }}</span>
                        </div>
                        @if($note->excerpt)
                            <div style="font-size:11.5px; color:#6b7280; margin-top:3px; line-height:1.4; max-height:2.8em; overflow:hidden;">{{ \Illuminate\Support\Str::limit($note->excerpt, 140) }}</div>
                        @endif
                        <div style="margin-top:4px;">
                            @if($note->type === \App\Models\InboxNotification::TYPE_CASE_FOLLOWUP && $note->case)
                                <span class="badge badge-investigating">Case {{ $note->case->case_number }}</span>
                            @elseif($note->type === \App\Models\InboxNotification::TYPE_REPORT_FOLLOWUP && $note->report)
                                <span class="badge badge-open">Report follow-up</span>
                                @if($note->report->target_ip)
                                    <span class="text-muted" style="font-size:11px; margin-left:4px; font-family:monospace;">{{ $note->report->target_ip }}</span>
                                @endif
                            @endif
                        </div>
                    </button>
                @empty
                    <div style="padding:24px 14px; text-align:center; color:#9ca3af; font-size:12px;">No notifications.</div>
                @endforelse
            </div>
        </div>
    @endif
</div>
