<div>
    {{-- KPIs --}}
    <div class="kpi-grid" style="margin-bottom:1rem;">
        <div class="kpi">
            <div class="kpi-label">Total IPs</div>
            <div class="kpi-value">{{ number_format($totalCount) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Active IPs</div>
            <div class="kpi-value" style="color:var(--success);">{{ number_format($activeCount) }}</div>
        </div>
    </div>

    {{-- Tabs --}}
    <div style="display:flex; gap:0.5rem; margin-bottom:1rem;">
        <button wire:click="$set('activeTab', 'list')" class="btn {{ $activeTab === 'list' ? 'btn-primary' : 'btn-secondary' }} btn-sm">IP List</button>
        <button wire:click="$set('activeTab', 'add')" class="btn {{ $activeTab === 'add' ? 'btn-primary' : 'btn-secondary' }} btn-sm">Add IP / Range</button>
        <button wire:click="$set('activeTab', 'bulk')" class="btn {{ $activeTab === 'bulk' ? 'btn-primary' : 'btn-secondary' }} btn-sm">Bulk Import</button>
    </div>

    <x-flash-messages />

    {{-- TAB: Add Single / Range --}}
    @if($activeTab === 'add')
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Add IP Address or Range</div>
            <p class="text-sm text-muted" style="margin-bottom:1rem;">
                Enter a single IP (<code>192.168.1.1</code> or <code>2001:db8::1</code>), CIDR range
                (<code>192.168.1.0/24</code> or <code>2001:db8::/120</code>), or IPv4 dash range
                (<code>192.168.1.1-192.168.1.50</code>).
                Ranges will be expanded into individual IPs (max 65 536).
            </p>

            <form wire:submit="addIp">
                <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div>
                        <label style="font-size:0.75rem;">IP Address / Range *</label>
                        <input type="text" wire:model="newIp" placeholder="192.168.1.0/24" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                        @error('newIp') <div style="color:#dc2626; font-size:0.75rem; margin-top:0.25rem;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label style="font-size:0.75rem;">Server Name (optional)</label>
                        <input type="text" wire:model="newServerName" placeholder="web-srv-01" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;">Customer (optional)</label>
                        <select wire:model="newCustomerId" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                            <option value="">— No customer —</option>
                            @foreach($customers as $c)
                                <option value="{{ $c->id }}">{{ $c->email }} {{ $c->company ? "({$c->company})" : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.75rem;">Tags (comma-separated)</label>
                        <input type="text" wire:model="newTags" placeholder="datacenter-1, rack-A" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                </div>
                <div style="margin-top:0.75rem;">
                    <label style="font-size:0.75rem;">Notes (optional)</label>
                    <input type="text" wire:model="newNotes" placeholder="Additional notes..." style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:1rem;">Add to Inventory</button>
            </form>
        </div>
    @endif

    {{-- TAB: Bulk Import --}}
    @if($activeTab === 'bulk')
        <div class="card">
            <div class="card-title" style="margin-bottom:0.75rem;">Bulk Import</div>
            <p class="text-sm text-muted" style="margin-bottom:1rem;">
                Paste one entry per line. Each line can be a single IP, CIDR range, or dash range.
                Optionally add a server name after a comma: <code>192.168.1.1,web-srv-01</code>.
                Lines starting with # are treated as comments.
            </p>

            <form wire:submit="bulkImport">
                <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:0.75rem;">
                    <div>
                        <label style="font-size:0.75rem;">Default Server Name (optional)</label>
                        <input type="text" wire:model="bulkServerName" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;">Customer (optional)</label>
                        <select wire:model="bulkCustomerId" style="padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%;">
                            <option value="">— No customer —</option>
                            @foreach($customers as $c)
                                <option value="{{ $c->id }}">{{ $c->email }} {{ $c->company ? "({$c->company})" : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label style="font-size:0.75rem;">IP List *</label>
                    <textarea wire:model="bulkText" rows="12" placeholder="# Example:
192.168.1.0/24
10.0.0.1,web-srv-01
10.0.0.2,web-srv-02
172.16.0.1-172.16.0.50" style="padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.8125rem; width:100%; font-family:monospace; resize:vertical;"></textarea>
                    @error('bulkText') <div style="color:#dc2626; font-size:0.75rem; margin-top:0.25rem;">{{ $message }}</div> @enderror
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:1rem;">Import All</button>
            </form>

            @if(! empty($importResults))
                <div style="margin-top:1rem; padding:0.75rem; background:#f9fafb; border-radius:6px; font-size:0.8125rem;">
                    <strong>Import Results:</strong>
                    Total lines: {{ $importResults['total'] ?? 0 }},
                    IPs imported: {{ $importResults['imported'] ?? 0 }},
                    Skipped: {{ $importResults['skipped'] ?? 0 }}
                </div>
            @endif
        </div>
    @endif

    {{-- TAB: IP List --}}
    @if($activeTab === 'list')
        <div class="filters">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search IPs, server names..." style="width:250px; min-width:0; flex:1; max-width:300px;">
            <select wire:model.live="filterStatus">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="reserved">Reserved</option>
                <option value="decommissioned">Decommissioned</option>
            </select>
            <select wire:model.live="filterCustomer">
                <option value="">All Customers</option>
                @foreach($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->email }}</option>
                @endforeach
            </select>
        </div>

        {{-- Bulk action bar --}}
        @if(count($selectedIps) > 0)
            <div style="background:#eff6ff; border:1px solid #bfdbfe; padding:10px 14px; border-radius:8px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                <strong style="color:#1e40af; font-size:13px;">{{ count($selectedIps) }} selected</strong>
                <button wire:click="clearSelection" class="btn btn-secondary btn-sm">Clear</button>

                <span style="width:1px; background:#bfdbfe; align-self:stretch;"></span>

                <div style="display:flex; gap:6px;">
                    <input type="text" wire:model="bulkTagAdd" list="ip-manager-popular-tags" placeholder="Add tag…" style="padding:4px 8px; border:1px solid #bfdbfe; border-radius:4px; font-size:12px; width:160px;">
                    <button wire:click="bulkApplyTag" class="btn btn-primary btn-sm" style="font-size:12px;">Add tag</button>
                </div>

                <div style="display:flex; gap:6px;">
                    <input type="text" wire:model="bulkTagRemove" list="ip-manager-popular-tags" placeholder="Remove tag…" style="padding:4px 8px; border:1px solid #bfdbfe; border-radius:4px; font-size:12px; width:160px;">
                    <button wire:click="bulkStripTag" class="btn btn-secondary btn-sm" style="font-size:12px;">Strip tag</button>
                </div>

                <div style="display:flex; gap:6px;">
                    <select wire:model="bulkSetCustomer" style="padding:4px 8px; border:1px solid #bfdbfe; border-radius:4px; font-size:12px;">
                        <option value="">— Customer —</option>
                        <option value="__none__">Unassign customer</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}">{{ $c->email }}</option>
                        @endforeach
                    </select>
                    <button wire:click="bulkApplyCustomer" class="btn btn-secondary btn-sm" style="font-size:12px;">Apply</button>
                </div>

                <div style="display:flex; gap:6px;">
                    <select wire:model="bulkSetStatus" style="padding:4px 8px; border:1px solid #bfdbfe; border-radius:4px; font-size:12px;">
                        <option value="">— Status —</option>
                        <option value="active">Active</option>
                        <option value="reserved">Reserved</option>
                        <option value="decommissioned">Decommissioned</option>
                    </select>
                    <button wire:click="bulkApplyStatus" class="btn btn-secondary btn-sm" style="font-size:12px;">Set</button>
                </div>

                <button wire:click="bulkDelete" onclick="return confirm('Delete {{ count($selectedIps) }} IP(s)?')" class="btn btn-danger btn-sm" style="font-size:12px; margin-left:auto;">Delete selected</button>
            </div>
        @endif

        {{-- Popular-tag datalist (shared between bulk bar and per-row quick-add) --}}
        <datalist id="ip-manager-popular-tags">
            @foreach($popularTags as $t)
                <option value="{{ $t }}"></option>
            @endforeach
        </datalist>

        <div class="card" style="padding:0; overflow:hidden;">
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:30px;">
                            <input type="checkbox" wire:model.live="selectAll" wire:change="toggleSelectAll">
                        </th>
                        <th>IP Address</th>
                        <th style="width:80px;">Scope</th>
                        <th>Server Name</th>
                        <th>Customer</th>
                        <th>Tags</th>
                        <th>Status</th>
                        <th>Added</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ips as $ip)
                        <tr wire:key="row-{{ $ip->id }}">
                            <td>
                                <input type="checkbox" wire:model.live="selectedIps" value="{{ $ip->id }}">
                            </td>
                            <td style="font-family:monospace; font-weight:500; word-break:break-all;">
                                {{ $ip->ip_address }}@if($ip->isBlock())/{{ $ip->prefix_length }}@endif
                                @if($ip->range_source)
                                    <div class="text-muted" style="font-family:monospace; font-size:11px;">from {{ $ip->range_source }}</div>
                                @endif
                            </td>
                            <td class="text-sm">
                                @if($ip->isBlock())
                                    <span class="badge" style="background:#dbeafe; color:#1e40af;">/{{ $ip->prefix_length }}</span>
                                @else
                                    <span class="text-muted">host</span>
                                @endif
                            </td>
                            <td>{{ $ip->server_name ?? '-' }}</td>
                            <td class="text-sm">
                                @if($ip->customer)
                                    <a href="/admin/customers/{{ $ip->customer_id }}" style="color:var(--primary);">{{ $ip->customer->email }}</a>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-sm" style="max-width:260px;">
                                <div style="display:flex; flex-wrap:wrap; gap:4px; align-items:center;">
                                    @foreach($ip->tags ?? [] as $tag)
                                        <span class="badge badge-open" style="display:inline-flex; align-items:center; gap:4px;">
                                            {{ $tag }}
                                            <button type="button"
                                                wire:click="quickRemoveTag('{{ $ip->id }}', @js($tag))"
                                                title="Remove tag"
                                                style="border:none; background:transparent; cursor:pointer; color:inherit; padding:0; line-height:1; font-size:12px;">×</button>
                                        </span>
                                    @endforeach
                                    <input type="text"
                                        list="ip-manager-popular-tags"
                                        placeholder="+ tag"
                                        x-data
                                        x-on:keydown.enter.prevent="if ($el.value.trim()) { $wire.quickAddTag('{{ $ip->id }}', $el.value.trim()); $el.value=''; }"
                                        style="border:1px dashed #cbd5e1; background:transparent; padding:2px 6px; border-radius:4px; font-size:11px; width:70px;">
                                </div>
                            </td>
                            <td>
                                <span class="badge {{ $ip->status === 'active' ? 'badge-resolved' : 'badge-closed' }}">
                                    {{ ucfirst($ip->status) }}
                                </span>
                            </td>
                            <td class="text-sm text-muted">{{ $ip->created_at->format('M j') }}</td>
                            <td>
                                <div style="display:flex; gap:4px;">
                                    <button wire:click="startEdit('{{ $ip->id }}')" class="btn btn-secondary btn-sm">Edit</button>
                                    <button wire:click="deleteIp('{{ $ip->id }}')" class="btn btn-danger btn-sm" onclick="return confirm('Delete this IP?')" title="Delete">×</button>
                                </div>
                            </td>
                        </tr>

                        {{-- Inline edit row --}}
                        @if($editingId === $ip->id)
                            <tr wire:key="edit-{{ $ip->id }}">
                                <td colspan="9" style="background:#f9fafb; padding:12px 16px;">
                                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:10px;">
                                        <div>
                                            <label style="font-size:11px; text-transform:uppercase; color:#6b7280;">Server name</label>
                                            <input type="text" wire:model="editServerName" style="width:100%; padding:4px 8px; border:1px solid var(--border); border-radius:4px; font-size:13px;">
                                        </div>
                                        <div>
                                            <label style="font-size:11px; text-transform:uppercase; color:#6b7280;">Customer</label>
                                            <select wire:model="editCustomerId" style="width:100%; padding:4px 8px; border:1px solid var(--border); border-radius:4px; font-size:13px;">
                                                <option value="">— No customer —</option>
                                                @foreach($customers as $c)
                                                    <option value="{{ $c->id }}">{{ $c->email }} {{ $c->company ? "({$c->company})" : '' }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:11px; text-transform:uppercase; color:#6b7280;">Status</label>
                                            <select wire:model="editStatus" style="width:100%; padding:4px 8px; border:1px solid var(--border); border-radius:4px; font-size:13px;">
                                                <option value="active">Active</option>
                                                <option value="reserved">Reserved</option>
                                                <option value="decommissioned">Decommissioned</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:11px; text-transform:uppercase; color:#6b7280;">Tags (comma-separated)</label>
                                            <input type="text" wire:model="editTags" placeholder="datacenter-1, rack-A" style="width:100%; padding:4px 8px; border:1px solid var(--border); border-radius:4px; font-size:13px;">
                                        </div>
                                    </div>
                                    <div style="margin-top:8px;">
                                        <label style="font-size:11px; text-transform:uppercase; color:#6b7280;">Notes</label>
                                        <input type="text" wire:model="editNotes" style="width:100%; padding:4px 8px; border:1px solid var(--border); border-radius:4px; font-size:13px;">
                                    </div>
                                    <div style="margin-top:10px; display:flex; gap:6px;">
                                        <button wire:click="saveEdit" class="btn btn-primary btn-sm">Save</button>
                                        <button wire:click="cancelEdit" class="btn btn-secondary btn-sm">Cancel</button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="9" style="text-align:center; padding:2rem; color:var(--text-muted);">No IPs in inventory. Add IPs using the "Add IP / Range" or "Bulk Import" tabs.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="mt-4">{{ $ips->links() }}</div>
    @endif
</div>
