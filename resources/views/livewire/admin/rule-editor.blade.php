<div>
    <x-flash-messages />

    @if(! $showForm)
        <div style="margin-bottom:1rem;">
            <button wire:click="create" class="btn btn-primary btn-sm">+ New Rule</button>
        </div>
    @endif

    {{-- Form --}}
    @if($showForm)
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">{{ $editingId ? 'Edit Rule' : 'New Rule' }}</div>
            <form wire:submit="save">
                <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div>
                        <label for="rule-name" style="font-size:0.75rem;">Name *</label>
                        <input id="rule-name" type="text" wire:model="name" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                        @error('name') <div style="color:#dc2626; font-size:0.75rem;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="rule-trigger" style="font-size:0.75rem;">Trigger Event *</label>
                        <select id="rule-trigger" wire:model="triggerEvent" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                            <option value="report_received">Report Received</option>
                            <option value="score_changed">Score Changed</option>
                            <option value="time_elapsed">Time Elapsed</option>
                            <option value="status_changed">Status Changed</option>
                            <option value="appeal_received">Appeal Received</option>
                        </select>
                    </div>
                    <div>
                        <label for="rule-priority" style="font-size:0.75rem;">Priority</label>
                        <input id="rule-priority" type="number" wire:model="priority" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                    <div>
                        <label for="rule-min-score" style="font-size:0.75rem;">Min Score</label>
                        <input id="rule-min-score" type="number" step="0.01" wire:model="minScore" placeholder="Optional" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                    <div>
                        <label for="rule-abuse-types" style="font-size:0.75rem;">Abuse Types (comma-separated, empty = all)</label>
                        <input id="rule-abuse-types" type="text" wire:model="abuseTypesJson" placeholder="spam, phishing, malware" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem; display:flex; align-items:center; gap:0.5rem;">
                            <input type="checkbox" wire:model="isActive"> Active
                        </label>
                    </div>
                </div>
                <div style="margin-top:0.75rem;" x-data="{ showHelp: false }">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label for="rule-conditions" style="font-size:0.75rem;">Conditions (JSON) *</label>
                        <button type="button" x-on:click="showHelp = !showHelp" style="background:none; border:none; color:var(--primary); font-size:0.75rem; cursor:pointer; text-decoration:underline;" x-text="showHelp ? 'Hide example' : 'Show example'"></button>
                    </div>
                    <textarea id="rule-conditions" wire:model="conditionsJson" rows="3"
                        placeholder='[{"field":"severity_score","operator":">=","value":60}]'
                        style="font-family:monospace; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">{{ $conditionsJson }}</textarea>
                    <div x-show="showHelp" x-cloak style="margin-top:6px; padding:8px 10px; background:#f5f3ff; border:1px solid #ddd6fe; border-radius:4px; font-size:11px; color:#5b21b6; font-family:monospace; white-space:pre;">[
  {"field": "severity_score", "operator": ">=", "value": 60},
  {"field": "abuse_type",     "operator": "in", "value": ["spam","phishing"]}
]
Operators: == != &gt; &gt;= &lt; &lt;= in not_in contains</div>
                    @error('conditionsJson') <div style="color:#dc2626; font-size:0.75rem;">{{ $message }}</div> @enderror
                </div>
                <div style="margin-top:0.75rem;" x-data="{ showHelp: false }">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label for="rule-actions" style="font-size:0.75rem;">Actions (JSON) *</label>
                        <button type="button" x-on:click="showHelp = !showHelp" style="background:none; border:none; color:var(--primary); font-size:0.75rem; cursor:pointer; text-decoration:underline;" x-text="showHelp ? 'Hide example' : 'Show example'"></button>
                    </div>
                    <textarea id="rule-actions" wire:model="actionsJson" rows="3"
                        placeholder='[{"type":"suspend_customer","params":{"grace_minutes":60}}]'
                        style="font-family:monospace; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">{{ $actionsJson }}</textarea>
                    <div x-show="showHelp" x-cloak style="margin-top:6px; padding:8px 10px; background:#f5f3ff; border:1px solid #ddd6fe; border-radius:4px; font-size:11px; color:#5b21b6; font-family:monospace; white-space:pre;">[
  {"type": "suspend_customer", "params": {"grace_minutes": 60}},
  {"type": "send_email",       "params": {"template": "customer_warning"}},
  {"type": "notify_slack",     "params": {"channel": "#abuse-critical"}},
  {"type": "block_ip",         "params": {}},
  {"type": "escalate",         "params": {"page_oncall": true}}
]</div>
                    @error('actionsJson') <div style="color:#dc2626; font-size:0.75rem;">{{ $message }}</div> @enderror
                </div>
                <div style="margin-top:1rem; display:flex; gap:0.5rem;">
                    <button type="submit" class="btn btn-primary btn-sm">{{ $editingId ? 'Update' : 'Create' }}</button>
                    <button type="button" wire:click="resetForm" class="btn btn-secondary btn-sm">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Table --}}
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Priority</th>
                    <th>Name</th>
                    <th>Trigger</th>
                    <th>Min Score</th>
                    <th>Types</th>
                    <th>Triggered</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rules as $rule)
                    <tr>
                        <td>{{ $rule->priority }}</td>
                        <td><strong>{{ $rule->name }}</strong></td>
                        <td><span class="badge badge-open">{{ str_replace('_', ' ', $rule->trigger_event) }}</span></td>
                        <td>{{ $rule->min_score ?? '-' }}</td>
                        <td class="text-sm">{{ $rule->abuse_types ? implode(', ', $rule->abuse_types) : 'All' }}</td>
                        <td class="text-sm text-muted">{{ $rule->trigger_count }}x</td>
                        <td>
                            <button wire:click="toggleActive('{{ $rule->id }}')"
                                aria-pressed="{{ $rule->is_active ? 'true' : 'false' }}"
                                onclick="return confirm('{{ $rule->is_active ? 'Disable' : 'Enable' }} rule \"{{ addslashes($rule->name) }}\"?')"
                                class="badge {{ $rule->is_active ? 'badge-resolved' : 'badge-closed' }}"
                                style="cursor:pointer; border:none;"
                                title="Click to toggle active state">
                                {{ $rule->is_active ? 'Active' : 'Disabled' }}
                            </button>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <button wire:click="edit('{{ $rule->id }}')" class="btn btn-secondary btn-sm">Edit</button>
                                <button wire:click="delete('{{ $rule->id }}')" class="btn btn-danger btn-sm" onclick="return confirm('Delete this rule?')">Delete</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="8" title="No automation rules yet"
                        message="Rules let you auto-suspend, warn, or escalate cases when conditions match. Click 'New Rule' above to create one." />
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
    <div class="mt-4">{{ $rules->links() }}</div>
</div>
