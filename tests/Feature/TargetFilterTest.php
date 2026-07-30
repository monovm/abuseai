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

it('filters cases by IP target', function () {
    $ipCase = AbuseCase::factory()->create(['target_ip' => '203.0.113.5', 'target_domain' => null]);
    $domainCase = AbuseCase::factory()->create(['target_ip' => null, 'target_domain' => 'evil.example']);

    $cases = Livewire::test(CaseQueue::class)
        ->set('filterTarget', 'ip')
        ->assertOk()
        ->viewData('cases');

    $ids = collect($cases->items())->pluck('id');
    expect($ids)->toContain($ipCase->id)->not->toContain($domainCase->id);
});

it('filters cases by domain target', function () {
    $ipCase = AbuseCase::factory()->create(['target_ip' => '203.0.113.5', 'target_domain' => null]);
    $domainCase = AbuseCase::factory()->create(['target_ip' => null, 'target_domain' => 'evil.example']);

    $cases = Livewire::test(CaseQueue::class)
        ->set('filterTarget', 'domain')
        ->viewData('cases');

    $ids = collect($cases->items())->pluck('id');
    expect($ids)->toContain($domainCase->id)->not->toContain($ipCase->id);
});

it('filters reports by URL target', function () {
    $urlReport = AbuseReport::factory()->create(['target_ip' => null, 'target_domain' => null, 'target_url' => 'https://evil.example/phish']);
    $ipReport = AbuseReport::factory()->create(['target_ip' => '203.0.113.5', 'target_domain' => null, 'target_url' => null]);

    $reports = Livewire::test(ReportQueue::class)
        ->set('filterTarget', 'url')
        ->viewData('reports');

    $ids = collect($reports->items())->pluck('id');
    expect($ids)->toContain($urlReport->id)->not->toContain($ipReport->id);
});

it('any-target value returns all rows', function () {
    AbuseCase::factory()->create(['target_ip' => '203.0.113.5']);
    AbuseCase::factory()->create(['target_domain' => 'evil.example']);

    $cases = Livewire::test(CaseQueue::class)
        ->set('filterTarget', '')
        ->viewData('cases');

    expect($cases->total())->toBeGreaterThanOrEqual(2);
});
