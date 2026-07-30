<?php

namespace App\Livewire\Admin;

use App\Concerns\AuthorizesAdmin;
use App\Jobs\AI\DraftCustomerNotice;
use App\Models\Customer;
use App\Models\SuspensionHistory;
use Livewire\Component;

class CustomerProfile extends Component
{
    use AuthorizesAdmin;

    public Customer $customer;
    public string $suspendReason = '';
    public string $notes = '';

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
        $this->notes = (string) $customer->notes;
    }

    public function saveNotes(): void
    {
        $this->customer->update(['notes' => $this->notes]);
        session()->flash('customer-success', 'Notes saved.');
    }

    public function suspend(): void
    {
        $this->authorizeAdmin();

        if ($this->customer->is_suspended) {
            return;
        }
        if (trim($this->suspendReason) === '') {
            session()->flash('customer-error', 'Please enter a suspension reason.');
            return;
        }

        $this->customer->update([
            'is_suspended' => true,
            'suspended_at' => now(),
        ]);
        SuspensionHistory::create([
            'customer_id' => $this->customer->id,
            'suspended_by' => auth()->id(),
            'suspension_reason' => $this->suspendReason,
            'suspended_at' => now(),
        ]);

        $this->suspendReason = '';
        $this->customer->refresh();
        session()->flash('customer-success', 'Customer suspended.');
    }

    public function unsuspend(): void
    {
        if (! $this->customer->is_suspended) {
            return;
        }

        $this->customer->update([
            'is_suspended' => false,
            'suspended_at' => null,
        ]);
        SuspensionHistory::where('customer_id', $this->customer->id)
            ->whereNull('unsuspended_at')
            ->latest('suspended_at')
            ->limit(1)
            ->update([
                'unsuspended_by' => auth()->id(),
                'unsuspended_at' => now(),
            ]);

        $this->customer->refresh();
        session()->flash('customer-success', 'Customer unsuspended.');
    }

    public function render()
    {
        return view('livewire.admin.customer-profile', [
            'cases' => $this->customer->cases()->latest()->take(20)->get(),
            'suspensions' => $this->customer->suspensionHistory()->latest('suspended_at')->take(10)->get(),
        ]);
    }
}
