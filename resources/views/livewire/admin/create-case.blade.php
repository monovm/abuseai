<div>
    <div class="card">
        <div class="card-title" style="margin-bottom:1rem;">Create New Case</div>

        <form wire:submit="save">
            {{-- Abuse Type --}}
            <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                <div>
                    <label style="font-size:0.75rem; font-weight:500;">Abuse Type *</label>
                    <select wire:model="abuseType" style="width:100%; padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                        @foreach($abuseTypes as $type)
                            <option value="{{ $type->value }}">{{ ucfirst($type->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:500;">Severity</label>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <select wire:model.live="severity" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                            <option value="auto">Auto-calculate</option>
                            <option value="manual">Manual score</option>
                        </select>
                        @if($severity === 'manual')
                            <input type="number" wire:model="manualScore" min="0" max="100" step="0.1" placeholder="0-100" style="width:100px; padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                        @endif
                    </div>
                </div>
            </div>

            {{-- Target --}}
            <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem;">
                <div>
                    <label style="font-size:0.75rem; font-weight:500;">Target IP</label>
                    <input type="text" wire:model.live.debounce.500ms="targetIp" placeholder="e.g. 185.100.10.1" style="width:100%; padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                    @error('targetIp') <div style="color:#dc2626; font-size:0.75rem; margin-top:0.25rem;">{{ $message }}</div> @enderror

                    {{-- IP lookup result --}}
                    @if($targetIp && filter_var($targetIp, FILTER_VALIDATE_IP))
                        <div style="margin-top:0.375rem; font-size:0.75rem;">
                            @if($ipInInventory)
                                <span style="color:var(--success);">Our IP — {{ $ipOwner }}</span>
                                @if($customerId)
                                    <span class="text-muted"> (customer auto-linked)</span>
                                @endif
                            @else
                                <span style="color:var(--warning);">IP not in inventory</span>
                            @endif
                        </div>
                    @endif
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:500;">Target Domain</label>
                    <input type="text" wire:model="targetDomain" placeholder="e.g. phishing-site.example" style="width:100%; padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                </div>
            </div>

            {{-- Customer --}}
            <div style="margin-top:1rem;">
                <label style="font-size:0.75rem; font-weight:500;">Customer (optional — auto-detected from IP)</label>
                <select wire:model="customerId" style="width:100%; padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                    <option value="">— No customer / Unknown —</option>
                    @foreach($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->email }} {{ $c->company ? "({$c->company})" : '' }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Reporter --}}
            <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem;">
                <div>
                    <label style="font-size:0.75rem; font-weight:500;">Reporter Name *</label>
                    <input type="text" wire:model="reporterName" placeholder="Who reported this" style="width:100%; padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                    @error('reporterName') <div style="color:#dc2626; font-size:0.75rem; margin-top:0.25rem;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:500;">Reporter Email *</label>
                    <input type="email" wire:model="reporterEmail" placeholder="reporter@example.com" style="width:100%; padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem;">
                    @error('reporterEmail') <div style="color:#dc2626; font-size:0.75rem; margin-top:0.25rem;">{{ $message }}</div> @enderror
                </div>
            </div>

            {{-- Description --}}
            <div style="margin-top:1rem;">
                <label style="font-size:0.75rem; font-weight:500;">Description / Report Content *</label>
                <textarea wire:model="description" rows="4" placeholder="Describe the abuse..." style="width:100%; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; resize:vertical;"></textarea>
                @error('description') <div style="color:#dc2626; font-size:0.75rem; margin-top:0.25rem;">{{ $message }}</div> @enderror
            </div>

            {{-- Evidence --}}
            <div style="margin-top:1rem;">
                <label style="font-size:0.75rem; font-weight:500;">Evidence (optional — headers, logs, URLs)</label>
                <textarea wire:model="evidence" rows="3" placeholder="Paste email headers, log entries, URLs, etc." style="width:100%; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; font-family:monospace; resize:vertical;"></textarea>
            </div>

            {{-- Submit --}}
            <div style="margin-top:1.25rem; display:flex; gap:0.75rem; align-items:center;">
                <button type="submit" class="btn btn-primary">Create Case</button>
                <a href="/admin/cases" class="btn btn-secondary">Cancel</a>
                <span wire:loading wire:target="save" class="text-sm text-muted">Creating...</span>
            </div>
        </form>
    </div>
</div>
