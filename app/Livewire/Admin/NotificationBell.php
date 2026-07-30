<?php

namespace App\Livewire\Admin;

use App\Models\InboxNotification;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    public bool $open = false;

    /**
     * Cached unread count so the badge can render without re-querying on
     * every Livewire poll cycle. Refreshed by refresh() / on-load.
     */
    public int $unreadCount = 0;

    public function mount(): void
    {
        $this->refresh();
    }

    #[On('inbox-notification-created')]
    public function refresh(): void
    {
        $this->unreadCount = InboxNotification::unread()->count();
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        if ($this->open) {
            $this->refresh();
        }
    }

    public function markRead(string $id): void
    {
        $note = InboxNotification::find($id);
        if (! $note) {
            return;
        }
        if (! $note->read_at) {
            $note->update(['read_at' => now()]);
        }
        $this->refresh();
        $this->redirect($note->targetUrl(), navigate: true);
    }

    public function markAllRead(): void
    {
        InboxNotification::unread()->update(['read_at' => now()]);
        $this->refresh();
    }

    public function render()
    {
        $items = InboxNotification::with(['case', 'report'])
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        return view('livewire.admin.notification-bell', [
            'items' => $items,
        ]);
    }
}
