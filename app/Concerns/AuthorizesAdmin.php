<?php

namespace App\Concerns;

/**
 * Tiny defense-in-depth helper for destructive Livewire actions.
 *
 * Most admin pages live behind `role:admin` at the route level, but a few
 * (cases, customers, IPs, email inbox) are reachable by any authenticated
 * role — agents and viewers if those roles are added later. Livewire AJAX
 * actions do NOT re-run the parent route's middleware on every call, so a
 * privilege-downgraded user could still hit `suspend()` etc. via the
 * `/livewire/update` endpoint.
 *
 * Usage in a Livewire component method that only admins should run:
 *
 *     public function suspend(): void
 *     {
 *         $this->authorizeAdmin();
 *         // …
 *     }
 */
trait AuthorizesAdmin
{
    protected function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }
}
