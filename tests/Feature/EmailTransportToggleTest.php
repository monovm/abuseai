<?php

use App\Livewire\Admin\BrandManager;
use App\Models\User;
use App\Services\Email\EmailTransportSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

it('defaults to Mandrill enabled on mount', function () {
    Livewire::test(BrandManager::class)
        ->assertSet('mandrillEnabled', true);

    expect(EmailTransportSettings::mandrillEnabled())->toBeTrue();
});

it('disables Mandrill from the admin toggle and persists it', function () {
    Livewire::test(BrandManager::class)
        ->call('toggleMandrill')
        ->assertSet('mandrillEnabled', false);

    // Persisted to disk so every subsequent send() (a separate request /
    // component) reads the switched-off state.
    expect(EmailTransportSettings::mandrillEnabled())->toBeFalse();
});

it('re-enables Mandrill on a second toggle', function () {
    EmailTransportSettings::setMandrillEnabled(false);

    Livewire::test(BrandManager::class)
        ->assertSet('mandrillEnabled', false)
        ->call('toggleMandrill')
        ->assertSet('mandrillEnabled', true);

    expect(EmailTransportSettings::mandrillEnabled())->toBeTrue();
});

it('a fresh page load reflects a previously disabled state', function () {
    // Simulate: operator disabled Mandrill in one request, then reloads
    // /admin/brands — the toggle must show the persisted "off" state.
    EmailTransportSettings::setMandrillEnabled(false);

    Livewire::test(BrandManager::class)
        ->assertSet('mandrillEnabled', false);
});
