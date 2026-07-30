<div>
    <x-flash-messages />

    @if(! $showForm)
        <div style="margin-bottom:16px;">
            <button wire:click="create" class="btn btn-primary btn-sm">+ New User</button>
        </div>
    @endif

    @if($showForm)
        <div class="card">
            <div class="card-title" style="margin-bottom:12px;">{{ $editingId ? 'Edit User' : 'Create User' }}</div>
            <form wire:submit="save">
                <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div>
                        <label for="user-name" style="font-size:12px; font-weight:500;">Name *</label>
                        <input id="user-name" type="text" wire:model="name" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        @error('name') <div style="color:#dc2626; font-size:12px; margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="user-email" style="font-size:12px; font-weight:500;">Email *</label>
                        <input id="user-email" type="email" wire:model="email" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        @error('email') <div style="color:#dc2626; font-size:12px; margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="user-password" style="font-size:12px; font-weight:500;">Password {{ $editingId ? '(leave blank to keep)' : '*' }}</label>
                        <x-password-input id="user-password" wire:model="password" />
                        @error('password') <div style="color:#dc2626; font-size:12px; margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="user-role" style="font-size:12px; font-weight:500;">Role *</label>
                        <select id="user-role" wire:model="role" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            @foreach($roles as $r)
                                <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div style="margin-top:16px; display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary btn-sm">{{ $editingId ? 'Update' : 'Create' }}</button>
                    <button type="button" wire:click="resetForm" class="btn btn-secondary btn-sm">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Assigned Cases</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td><strong>{{ $user->name }}</strong></td>
                        <td class="text-sm">{{ $user->email }}</td>
                        <td>
                            @foreach($user->roles as $r)
                                <span class="badge badge-{{ $r->name === 'admin' ? 'critical' : ($r->name === 'agent' ? 'open' : 'closed') }}">{{ ucfirst($r->name) }}</span>
                            @endforeach
                        </td>
                        <td>{{ $user->assigned_cases_count }}</td>
                        <td class="text-sm text-muted">{{ $user->created_at->format('M j, Y') }}</td>
                        <td>
                            <div class="flex gap-2">
                                <button wire:click="edit({{ $user->id }})" class="btn btn-secondary btn-sm">Edit</button>
                                @if($user->id !== auth()->id())
                                    <button wire:click="deleteUser({{ $user->id }})" class="btn btn-danger btn-sm" onclick="return confirm('Delete {{ $user->name }}?')">Delete</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:2rem; color:#6b7280;">No users</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</div>
