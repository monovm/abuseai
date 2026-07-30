<?php

namespace App\Livewire\Admin;

use App\Concerns\AuthorizesAdmin;
use App\Models\Customer;
use App\Models\IpAddress;
use App\Services\IpInventoryService;
use Livewire\Component;
use Livewire\WithPagination;

class IpManager extends Component
{
    use AuthorizesAdmin;
    use WithPagination;

    // Filters
    public string $search = '';
    public string $filterStatus = '';
    public string $filterCustomer = '';

    // Single IP form
    public string $newIp = '';
    public string $newServerName = '';
    public string $newCustomerId = '';
    public string $newTags = '';
    public string $newNotes = '';

    // Bulk import
    public string $bulkText = '';
    public string $bulkServerName = '';
    public string $bulkCustomerId = '';

    // UI state
    public string $activeTab = 'list'; // list, add, bulk
    public array $importResults = [];

    // Inline edit (one row at a time)
    public ?string $editingId = null;
    public string $editServerName = '';
    public string $editCustomerId = '';
    public string $editTags = '';
    public string $editNotes = '';
    public string $editStatus = 'active';

    // Bulk selection + bulk action form state
    public array $selectedIps = [];
    public bool $selectAll = false;
    public string $bulkTagAdd = '';
    public string $bulkTagRemove = '';
    public string $bulkSetCustomer = '';
    public string $bulkSetStatus = '';

    protected $queryString = ['search' => ['as' => 'q'], 'filterStatus' => ['as' => 'status']];

    public function updatingSearch()
    {
        $this->resetPage();
        $this->selectAll = false;
        $this->selectedIps = [];
    }

    public function updatingFilterStatus()
    {
        $this->resetPage();
        $this->selectAll = false;
        $this->selectedIps = [];
    }

    public function updatingFilterCustomer()
    {
        $this->resetPage();
        $this->selectAll = false;
        $this->selectedIps = [];
    }

    /**
     * Add a single IP or range.
     */
    public function addIp(IpInventoryService $service): void
    {
        $this->validate([
            'newIp' => ['required', 'string', 'max:50'],
            'newServerName' => ['nullable', 'string', 'max:255'],
        ]);

        $tags = $this->newTags
            ? array_map('trim', explode(',', $this->newTags))
            : null;

        $result = $service->import(
            $this->newIp,
            $this->newServerName ?: null,
            $this->newCustomerId ?: null,
            $tags,
            $this->newNotes ?: null,
        );

        if ($result['type'] === 'invalid') {
            $this->addError('newIp', 'Invalid IP address, CIDR, or range format.');
            return;
        }

        $message = match ($result['type']) {
            'block' => "Stored block {$result['input']} as a single inventory entry.",
            'cidr_expanded' => "Expanded {$result['input']} into {$result['count']} host rows.",
            'range' => "Expanded dash range into {$result['count']} host rows.",
            'single' => "Added host {$result['input']}.",
            default => "Imported {$result['count']} entries from {$result['type']} input: {$result['input']}",
        };
        session()->flash('success', $message);

        $this->reset(['newIp', 'newServerName', 'newCustomerId', 'newTags', 'newNotes']);
    }

    /**
     * Bulk import from text area.
     */
    public function bulkImport(IpInventoryService $service): void
    {
        $this->validate([
            'bulkText' => ['required', 'string'],
        ]);

        $results = $service->bulkImport(
            $this->bulkText,
            $this->bulkServerName ?: null,
            $this->bulkCustomerId ?: null,
        );

        $this->importResults = $results;

        session()->flash('success', "Bulk import complete: {$results['imported']} IPs imported, {$results['skipped']} skipped.");

        $this->reset(['bulkText', 'bulkServerName', 'bulkCustomerId']);
    }

    /**
     * Delete an IP from inventory.
     */
    public function deleteIp(string $id): void
    {
        $this->authorizeAdmin();
        IpAddress::findOrFail($id)->delete();
    }

    /**
     * Toggle IP status (active/decommissioned).
     */
    public function toggleStatus(string $id): void
    {
        $ip = IpAddress::findOrFail($id);
        $ip->update([
            'status' => $ip->status === 'active' ? 'decommissioned' : 'active',
        ]);
    }

    // -------- Inline edit --------

    public function startEdit(string $id): void
    {
        $ip = IpAddress::findOrFail($id);
        $this->editingId = $ip->id;
        $this->editServerName = (string) ($ip->server_name ?? '');
        $this->editCustomerId = (string) ($ip->customer_id ?? '');
        $this->editTags = implode(', ', $ip->tags ?? []);
        $this->editNotes = (string) ($ip->notes ?? '');
        $this->editStatus = (string) ($ip->status ?? 'active');
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->reset(['editServerName', 'editCustomerId', 'editTags', 'editNotes', 'editStatus']);
    }

    public function saveEdit(): void
    {
        if (! $this->editingId) {
            return;
        }
        $ip = IpAddress::findOrFail($this->editingId);

        $tags = $this->editTags
            ? array_values(array_filter(array_map('trim', explode(',', $this->editTags))))
            : null;

        $ip->update([
            'server_name' => $this->editServerName ?: null,
            'customer_id' => $this->editCustomerId ?: null,
            'tags' => $tags,
            'notes' => $this->editNotes ?: null,
            'status' => $this->editStatus ?: 'active',
        ]);

        session()->flash('success', "Updated {$ip->ip_address}" . ($ip->isBlock() ? "/{$ip->prefix_length}" : '') . '.');
        $this->cancelEdit();
    }

    /**
     * Add a single tag to one IP from the list view (no modal).
     */
    public function quickAddTag(string $id, string $tag): void
    {
        $tag = trim($tag);
        if ($tag === '') {
            return;
        }
        $ip = IpAddress::findOrFail($id);
        $tags = array_values(array_unique(array_merge($ip->tags ?? [], [$tag])));
        $ip->update(['tags' => $tags]);
    }

    public function quickRemoveTag(string $id, string $tag): void
    {
        $ip = IpAddress::findOrFail($id);
        $tags = array_values(array_filter($ip->tags ?? [], fn ($t) => $t !== $tag));
        $ip->update(['tags' => $tags ?: null]);
    }

    // -------- Bulk selection --------

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedIps = $this->visibleIds();
        } else {
            $this->selectedIps = [];
        }
    }

    public function clearSelection(): void
    {
        $this->selectedIps = [];
        $this->selectAll = false;
    }

    /**
     * Returns the IDs currently rendered on the page (so "select all" only picks
     * up what the operator is actually looking at).
     */
    protected function visibleIds(): array
    {
        return $this->buildQuery()->paginate(50)->pluck('id')->all();
    }

    // -------- Bulk actions --------

    public function bulkApplyTag(): void
    {
        $tag = trim($this->bulkTagAdd);
        if ($tag === '' || empty($this->selectedIps)) {
            return;
        }
        $count = 0;
        foreach (IpAddress::whereIn('id', $this->selectedIps)->get() as $ip) {
            $tags = array_values(array_unique(array_merge($ip->tags ?? [], [$tag])));
            $ip->update(['tags' => $tags]);
            $count++;
        }
        $this->bulkTagAdd = '';
        session()->flash('success', "Added tag '{$tag}' to {$count} IP(s).");
    }

    public function bulkStripTag(): void
    {
        $tag = trim($this->bulkTagRemove);
        if ($tag === '' || empty($this->selectedIps)) {
            return;
        }
        $count = 0;
        foreach (IpAddress::whereIn('id', $this->selectedIps)->get() as $ip) {
            $before = $ip->tags ?? [];
            $after = array_values(array_filter($before, fn ($t) => $t !== $tag));
            if ($before !== $after) {
                $ip->update(['tags' => $after ?: null]);
                $count++;
            }
        }
        $this->bulkTagRemove = '';
        session()->flash('success', "Removed tag '{$tag}' from {$count} IP(s).");
    }

    public function bulkApplyCustomer(): void
    {
        if (empty($this->selectedIps) || $this->bulkSetCustomer === '') {
            return;
        }
        $customerId = $this->bulkSetCustomer === '__none__' ? null : $this->bulkSetCustomer;
        $count = IpAddress::whereIn('id', $this->selectedIps)->update(['customer_id' => $customerId]);
        $this->bulkSetCustomer = '';
        $who = $customerId ? (Customer::find($customerId)?->email ?? 'customer') : 'no customer';
        session()->flash('success', "Assigned {$count} IP(s) to {$who}.");
    }

    public function bulkApplyStatus(): void
    {
        if (empty($this->selectedIps) || ! in_array($this->bulkSetStatus, ['active', 'reserved', 'decommissioned'])) {
            return;
        }
        $count = IpAddress::whereIn('id', $this->selectedIps)->update(['status' => $this->bulkSetStatus]);
        $status = $this->bulkSetStatus;
        $this->bulkSetStatus = '';
        session()->flash('success', "Set status '{$status}' on {$count} IP(s).");
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedIps)) {
            return;
        }
        $count = IpAddress::whereIn('id', $this->selectedIps)->count();
        IpAddress::whereIn('id', $this->selectedIps)->delete();
        $this->clearSelection();
        session()->flash('success', "Deleted {$count} IP(s).");
    }

    // -------- Query --------

    protected function buildQuery()
    {
        $query = IpAddress::with('customer');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('ip_address', 'like', "%{$this->search}%")
                  ->orWhere('server_name', 'like', "%{$this->search}%")
                  ->orWhere('range_source', 'like', "%{$this->search}%");
            });
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterCustomer) {
            $query->where('customer_id', $this->filterCustomer);
        }

        return $query;
    }

    public function render()
    {
        $ips = $this->buildQuery()->paginate(50);

        // Most-used tags across the inventory — for quick-tag autocomplete hints.
        $popularTags = IpAddress::whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(15)
            ->keys()
            ->values();

        return view('livewire.admin.ip-manager', [
            'ips' => $ips,
            'totalCount' => IpAddress::count(),
            'activeCount' => IpAddress::where('status', 'active')->count(),
            'customers' => Customer::orderBy('email')->get(['id', 'email', 'company']),
            'popularTags' => $popularTags,
        ]);
    }
}
