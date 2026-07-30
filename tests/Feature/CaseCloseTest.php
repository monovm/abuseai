<?php

use App\Enums\ActionType;
use App\Enums\CaseStatus;
use App\Livewire\Admin\CaseDetail;
use App\Models\AbuseCase;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

it('closes a case with a reason and writes a timeline action', function () {
    $case = AbuseCase::factory()->create([
        'status' => CaseStatus::Open,
        'closed_at' => null,
    ]);

    Livewire::test(CaseDetail::class, ['case' => $case])
        ->call('openCloseForm')
        ->set('closeReason', 'marketing_or_spam_pitch')
        ->set('closeNote', 'Paid guest-posting pitch from london-post.co.uk.')
        ->call('closeCase')
        ->assertHasNoErrors();

    $case->refresh();
    expect($case->status)->toBe(CaseStatus::Closed);
    expect($case->closed_at)->not->toBeNull();
    expect($case->metadata['closure_reason'])->toBe('marketing_or_spam_pitch');
    expect($case->metadata['closure_reason_label'])->toBe('Marketing / SEO pitch (not abuse)');
    expect($case->metadata['closure_note'])->toBe('Paid guest-posting pitch from london-post.co.uk.');

    $action = $case->actions()->latest('created_at')->first();
    expect($action->action_type)->toBe(ActionType::Closed);
    expect($action->payload['reason'])->toBe('marketing_or_spam_pitch');
    expect($action->payload['from'])->toBe('open');
    expect($action->payload['to'])->toBe('closed');
});

it('rejects close attempt with no reason selected', function () {
    $case = AbuseCase::factory()->create(['status' => CaseStatus::Open]);

    Livewire::test(CaseDetail::class, ['case' => $case])
        ->call('openCloseForm')
        ->set('closeReason', '')
        ->call('closeCase');

    $case->refresh();
    expect($case->status)->toBe(CaseStatus::Open);
    expect($case->closed_at)->toBeNull();
});

it('requires a note when closing with reason other', function () {
    $case = AbuseCase::factory()->create(['status' => CaseStatus::Open]);

    Livewire::test(CaseDetail::class, ['case' => $case])
        ->call('openCloseForm')
        ->set('closeReason', 'other')
        ->set('closeNote', '')
        ->call('closeCase');

    $case->refresh();
    expect($case->status)->toBe(CaseStatus::Open);
    expect($case->closed_at)->toBeNull();
});

it('refuses to re-close an already closed case', function () {
    $case = AbuseCase::factory()->create([
        'status' => CaseStatus::Closed,
        'closed_at' => now()->subHour(),
    ]);

    $beforeActionCount = $case->actions()->count();

    Livewire::test(CaseDetail::class, ['case' => $case])
        ->call('openCloseForm')
        ->set('closeReason', 'not_correct')
        ->call('closeCase');

    $case->refresh();
    expect($case->actions()->count())->toBe($beforeActionCount);
});

it('forbids non-admin users from closing a case', function () {
    $nonAdmin = User::factory()->create();
    $this->actingAs($nonAdmin);

    $case = AbuseCase::factory()->create(['status' => CaseStatus::Open]);

    Livewire::test(CaseDetail::class, ['case' => $case])
        ->set('closeReason', 'not_correct')
        ->call('closeCase')
        ->assertStatus(403);

    $case->refresh();
    expect($case->status)->toBe(CaseStatus::Open);
});
