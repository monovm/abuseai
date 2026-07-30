<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

class UserManager extends Component
{
    use WithPagination;

    // Form
    public ?string $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $role = 'agent';
    public bool $showForm = false;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);
        $this->editingId = (string) $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->role = $user->roles->first()?->name ?? 'agent';
        $this->showForm = true;
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string'],
        ];

        if (! $this->editingId) {
            $rules['password'] = ['required', 'string', 'min:8'];
            $rules['email'][] = 'unique:users,email';
        } else {
            $rules['email'][] = 'unique:users,email,' . $this->editingId;
        }

        $this->validate($rules);

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);
            $user->update([
                'name' => $this->name,
                'email' => $this->email,
            ]);

            if ($this->password) {
                $user->update(['password' => Hash::make($this->password)]);
            }

            $user->syncRoles([$this->role]);
            session()->flash('success', "User {$user->name} updated.");
        } else {
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make($this->password),
                'email_verified_at' => now(),
            ]);

            $user->assignRole($this->role);
            session()->flash('success', "User {$user->name} created.");
        }

        $this->resetForm();
    }

    public function deleteUser(int $id): void
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'You cannot delete your own account.');
            return;
        }

        $user->delete();
        session()->flash('success', "User {$user->name} deleted.");
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->role = 'agent';
        $this->showForm = false;
    }

    public function render()
    {
        return view('livewire.admin.user-manager', [
            'users' => User::with('roles')->withCount('assignedCases')->orderBy('name')->paginate(25),
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }
}
