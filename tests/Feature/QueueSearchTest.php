<?php

use App\Livewire\Admin\CaseQueue;
use App\Livewire\Admin\ReportQueue;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

it('searches cases by an external case number stored in a JSON column', function () {
    $match = AbuseCase::factory()->create([
        'external_case_numbers' => ['POL-2026-0099', 'CERT-AB-12'],
    ]);
    $other = AbuseCase::factory()->create([
        'external_case_numbers' => ['DMCA-2026-7777'],
    ]);

    $cases = Livewire::test(CaseQueue::class)
        ->set('search', 'POL-2026-0099')
        ->assertOk()
        ->viewData('cases');

    $ids = collect($cases->items())->pluck('id');
    expect($ids)->toContain($match->id)->not->toContain($other->id);
});

it('searches cases by an extra target IP stored in a JSON column', function () {
    $match = AbuseCase::factory()->create([
        'extra_target_ips' => ['203.0.113.50'],
    ]);
    $other = AbuseCase::factory()->create([
        'extra_target_ips' => ['198.51.100.7'],
    ]);

    $cases = Livewire::test(CaseQueue::class)
        ->set('search', '203.0.113.50')
        ->assertOk()
        ->viewData('cases');

    $ids = collect($cases->items())->pluck('id');
    expect($ids)->toContain($match->id)->not->toContain($other->id);
});

it('searches reports by an external case number stored in a JSON column', function () {
    $match = AbuseReport::factory()->create([
        'external_case_numbers' => ['ISP-TICKET-55021'],
    ]);
    $other = AbuseReport::factory()->create([
        'external_case_numbers' => ['ISP-TICKET-10000'],
    ]);

    $reports = Livewire::test(ReportQueue::class)
        ->set('search', 'ISP-TICKET-55021')
        ->assertOk()
        ->viewData('reports');

    $ids = collect($reports->items())->pluck('id');
    expect($ids)->toContain($match->id)->not->toContain($other->id);
});
