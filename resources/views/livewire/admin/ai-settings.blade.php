<div>
    <x-flash-messages />

    {{-- Provider Selection --}}
    <div class="card">
        <div class="card-title" style="margin-bottom:1rem;">Active AI Provider</div>

        <div class="resp-grid-2" role="radiogroup" aria-label="AI provider" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            @foreach($providers as $key => $provider)
                <div role="radio"
                     tabindex="0"
                     aria-checked="{{ $activeProvider === $key ? 'true' : 'false' }}"
                     style="border:2px solid {{ $activeProvider === $key ? '#2563eb' : 'var(--border)' }}; border-radius:8px; padding:1.25rem; cursor:pointer; transition:all 0.15s; outline-offset:2px;"
                     wire:click="$set('activeProvider', '{{ $key }}')"
                     onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                        <div style="font-weight:600; font-size:0.9375rem;">{{ $provider['name'] }}</div>
                        @if($activeProvider === $key)
                            <span class="badge badge-resolved">Active</span>
                        @endif
                    </div>
                    <div class="text-sm text-muted">Model: {{ $provider['model'] }}</div>
                    <div class="text-sm" style="margin-top:0.25rem;">
                        @if($provider['configured'])
                            <span style="color:var(--success);">API key configured</span>
                        @else
                            <span style="color:var(--danger);">API key not set</span>
                        @endif
                    </div>

                    {{-- Test connection --}}
                    <div style="margin-top:0.75rem;">
                        <button wire:click="testProvider('{{ $key }}')" class="btn btn-secondary btn-sm" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="testProvider('{{ $key }}')">Test Connection</span>
                            <span wire:loading wire:target="testProvider('{{ $key }}')">Testing...</span>
                        </button>
                        @if(session("test-{$key}"))
                            <span style="color:var(--success); font-size:0.75rem; margin-left:0.5rem;">{{ session("test-{$key}") }}</span>
                        @endif
                        @if(session("test-{$key}-error"))
                            <span style="color:var(--danger); font-size:0.75rem; margin-left:0.5rem;">{{ session("test-{$key}-error") }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <button wire:click="save" class="btn btn-primary btn-sm" style="margin-top:1rem;">Save Provider Selection</button>
    </div>

    {{-- Model Configuration --}}
    <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
        {{-- Claude Settings --}}
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Anthropic Claude</div>
            <div style="margin-bottom:0.75rem;">
                <label style="font-size:0.75rem;">Model</label>
                <select wire:model="claudeModel" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    @foreach($claudeModels as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <div style="margin-top:0.375rem;">
                    <input type="text" wire:model="claudeModel" placeholder="Or type custom model ID..." style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.75rem; width:100%; color:var(--text-muted);">
                </div>
            </div>
            <div class="text-sm text-muted">
                API Key: <span class="badge {{ config('ai.providers.claude.api_key') ? 'badge-resolved' : 'badge-critical' }}">{{ config('ai.providers.claude.api_key') ? 'Configured' : 'Not set' }}</span>
            </div>
        </div>

        {{-- OpenAI Settings --}}
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">OpenAI</div>
            <div style="margin-bottom:0.75rem;">
                <label style="font-size:0.75rem;">Model</label>
                <select wire:model="openaiModel" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    @foreach($openaiModels as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <div style="margin-top:0.375rem;">
                    <input type="text" wire:model="openaiModel" placeholder="Or type custom model ID..." style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.75rem; width:100%; color:var(--text-muted);">
                </div>
            </div>
            <div class="text-sm text-muted">
                API Key: <span class="badge {{ config('ai.providers.openai.api_key') ? 'badge-resolved' : 'badge-critical' }}">{{ config('ai.providers.openai.api_key') ? 'Configured' : 'Not set' }}</span>
            </div>
        </div>
    </div>

    {{-- Info --}}
    <div class="card" style="background:#f9fafb;">
        <div class="card-title" style="margin-bottom:0.5rem;">How it works</div>
        <ul class="text-sm" style="padding-left:1.25rem; color:var(--text-muted); line-height:1.8;">
            <li>All AI features (triage, scoring, pattern detection, drafts) use the selected provider</li>
            <li>Switching providers takes effect immediately for new AI calls</li>
            <li>Running jobs will complete with the provider they started with</li>
            <li>All AI calls are logged in the audit trail with provider + model info</li>
            <li>API keys are set in <code>.env</code> — not editable from the UI for security</li>
        </ul>
    </div>
</div>
