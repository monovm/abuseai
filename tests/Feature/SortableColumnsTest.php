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

it('report queue accepts each whitelisted sort column and toggles direction', function () {
    AbuseReport::factory()->count(3)->create();

    $columns = [
        'reported_at', 'created_at', 'case_id', 'source', 'abuse_type',
        'target_ip', 'reporter_id', 'ai_noise_score',
    ];

    foreach ($columns as $column) {
        $c = Livewire::test(ReportQueue::class)
            ->set('sortBy', 'id')         // unused column, forces "new column" branch
            ->set('sortDir', 'asc');
        $c->call('applySort',$column);
        expect($c->get('sortBy'))->toBe($column);
        expect($c->get('sortDir'))->toBe('desc');

        $c->call('applySort',$column);
        expect($c->get('sortDir'))->toBe('asc');
    }
});

it('report queue rejects non-whitelisted sort columns', function () {
    AbuseReport::factory()->create();
    $c = Livewire::test(ReportQueue::class);
    $original = $c->get('sortBy');

    $c->call('applySort',"evidence; DROP TABLE users; --");

    expect($c->get('sortBy'))->toBe($original);
});

it('case queue accepts each whitelisted sort column', function () {
    AbuseCase::factory()->count(3)->create();

    $columns = [
        'case_number', 'status', 'abuse_type', 'severity_score',
        'target_ip', 'report_count', 'created_at', 'sla_deadline',
    ];

    foreach ($columns as $column) {
        $c = Livewire::test(CaseQueue::class);
        $c->call('applySort',$column);
        expect($c->get('sortBy'))->toBe($column);
    }
});

it('case queue rejects non-whitelisted sort columns', function () {
    AbuseCase::factory()->create();
    $c = Livewire::test(CaseQueue::class);
    $original = $c->get('sortBy');

    $c->call('applySort','closed_at');

    expect($c->get('sortBy'))->toBe($original);
});
