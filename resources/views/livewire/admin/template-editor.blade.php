<div>
    <x-flash-messages />

    @if(! $showForm)
        <div style="margin-bottom:1rem;">
            <button wire:click="create" class="btn btn-primary btn-sm">+ New Template</button>
        </div>
    @endif

    @if($showForm)
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">{{ $editingId ? 'Edit Template' : 'New Template' }}</div>
            <form wire:submit="save">
                <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div>
                        <label for="tpl-name" style="font-size:0.75rem;">Name *</label>
                        <input id="tpl-name" type="text" wire:model="name" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                    <div>
                        <label for="tpl-slug" style="font-size:0.75rem;">Slug *</label>
                        <input id="tpl-slug" type="text" wire:model="slug" placeholder="e.g. reporter-ack" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                    <div>
                        <label for="tpl-type" style="font-size:0.75rem;">Type *</label>
                        <select id="tpl-type" wire:model="type" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                            <option value="reporter_ack">Reporter ACK</option>
                            <option value="reporter_status">Reporter Status</option>
                            <option value="reporter_resolved">Reporter Resolved</option>
                            <option value="customer_warning">Customer Warning</option>
                            <option value="customer_suspension">Customer Suspension</option>
                            <option value="customer_appeal_result">Appeal Result</option>
                        </select>
                    </div>
                    <div>
                        <label for="tpl-abuse-type" style="font-size:0.75rem;">Abuse Type (empty = all)</label>
                        <select id="tpl-abuse-type" wire:model="abuseType" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                            <option value="">All Types</option>
                            @foreach(\App\Enums\AbuseType::cases() as $t)
                                <option value="{{ $t->value }}">{{ ucfirst($t->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @php
                    $mergeVars = ['case_number','case_url','abuse_type','severity_level','severity_score','target_ip','target_domain','reporter_name','reporter_email','customer_email','customer_name','brand_name','brand_from','evidence_summary','date'];
                @endphp

                <div style="margin-top:0.75rem; padding:8px 10px; background:#f9fafb; border:1px solid var(--border); border-radius:6px;">
                    <div style="font-size:0.6875rem; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;">Available merge variables (click to copy)</div>
                    <div style="display:flex; flex-wrap:wrap; gap:4px;">
                        @foreach($mergeVars as $var)
                            @php $token = '{{ ' . $var . ' }}'; @endphp
                            <button type="button"
                                data-token="{{ $token }}"
                                onclick="const t=this.dataset.token; navigator.clipboard.writeText(t); this.textContent='copied!'; setTimeout(()=>this.textContent=t, 800);"
                                style="font-family:monospace; font-size:11px; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:4px; padding:2px 6px; cursor:pointer;">{{ $token }}</button>
                        @endforeach
                    </div>
                </div>

                <div style="margin-top:0.75rem;">
                    <label for="tpl-subject" style="font-size:0.75rem;">Subject *</label>
                    <input id="tpl-subject" type="text" wire:model="subject" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                </div>
                <div style="margin-top:0.75rem;">
                    <label for="tpl-body-html" style="font-size:0.75rem;">Body HTML *</label>
                    <textarea id="tpl-body-html" wire:model="bodyHtml" rows="8" style="font-family:monospace; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">{{ $bodyHtml }}</textarea>
                </div>
                <div style="margin-top:0.75rem;">
                    <label for="tpl-body-text" style="font-size:0.75rem;">Body Text *</label>
                    <textarea id="tpl-body-text" wire:model="bodyText" rows="6" style="font-family:monospace; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">{{ $bodyText }}</textarea>
                </div>

                @if($showPreview)
                    @php $preview = $this->previewRendered(); @endphp
                    <div style="margin-top:0.75rem; padding:12px; background:#f5f3ff; border:1px solid #ddd6fe; border-radius:6px;">
                        <div style="font-size:0.6875rem; text-transform:uppercase; color:#5b21b6; margin-bottom:6px;">Preview (rendered with sample data)</div>
                        <div style="font-size:13px;"><strong>Subject:</strong> {{ $preview['subject'] }}</div>
                        <div style="margin-top:8px; font-size:0.6875rem; text-transform:uppercase; color:#5b21b6;">HTML</div>
                        <iframe sandbox srcdoc="{{ $preview['body_html'] }}" style="width:100%; min-height:200px; border:1px solid var(--border); border-radius:4px; background:#fff;"></iframe>
                        <div style="margin-top:8px; font-size:0.6875rem; text-transform:uppercase; color:#5b21b6;">Text</div>
                        <pre style="white-space:pre-wrap; font-size:12px; padding:8px; background:#fff; border:1px solid var(--border); border-radius:4px; max-height:200px; overflow-y:auto;">{{ $preview['body_text'] }}</pre>
                    </div>
                @endif
                <div style="display:grid; grid-template-columns:1fr auto; gap:1rem; margin-top:0.75rem;">
                    <div>
                        <label style="font-size:0.75rem;">Variables (comma-separated)</label>
                        <input type="text" wire:model="variablesText" placeholder="case_number, reporter_name, ..." style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem; display:flex; align-items:center; gap:0.5rem;">
                            <input type="checkbox" wire:model="isActive"> Active
                        </label>
                    </div>
                </div>
                <div style="margin-top:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary btn-sm">{{ $editingId ? 'Update' : 'Create' }}</button>
                    <button type="button" wire:click="togglePreview" class="btn btn-secondary btn-sm">{{ $showPreview ? 'Hide preview' : 'Preview' }}</button>
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
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Type</th>
                    <th>Subject</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($templates as $t)
                    <tr>
                        <td><strong>{{ $t->name }}</strong></td>
                        <td class="text-sm text-muted">{{ $t->slug }}</td>
                        <td><span class="badge badge-open">{{ str_replace('_', ' ', $t->type) }}</span></td>
                        <td class="text-sm">{{ Str::limit($t->subject, 40) }}</td>
                        <td>
                            <span class="badge {{ $t->is_active ? 'badge-resolved' : 'badge-closed' }}">
                                {{ $t->is_active ? 'Active' : 'Disabled' }}
                            </span>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <button wire:click="edit('{{ $t->id }}')" class="btn btn-secondary btn-sm">Edit</button>
                                <button wire:click="delete('{{ $t->id }}')" class="btn btn-danger btn-sm" onclick="return confirm('Delete template \'{{ addslashes($t->name ?? $t->slug) }}\'? This cannot be undone.')">Delete</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="6" title="No email templates yet"
                        message="Templates power reporter acks, customer notices, and appeal results. Add one above to get started." />
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
    <div class="mt-4">{{ $templates->links() }}</div>
</div>
