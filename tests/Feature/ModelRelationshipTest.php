<?php

use App\Enums\ActionType;
use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\CaseAction;
use App\Models\Customer;
use App\Models\Reporter;
use App\Models\SuspensionHistory;

test('reporter has many reports', function () {
    $reporter = Reporter::factory()->create();
    AbuseReport::factory()->count(3)->create(['reporter_id' => $reporter->id]);

    expect($reporter->reports)->toHaveCount(3);
});

test('customer has many cases', function () {
    $customer = Customer::factory()->create();
    AbuseCase::factory()->count(2)->create(['customer_id' => $customer->id]);

    expect($customer->cases)->toHaveCount(2);
});

test('case has many reports', function () {
    $case = AbuseCase::factory()->create();
    $reporter = Reporter::factory()->create();

    AbuseReport::factory()->count(4)->create([
        'case_id' => $case->id,
        'reporter_id' => $reporter->id,
    ]);

    expect($case->reports)->toHaveCount(4);
});

test('case has many actions', function () {
    $case = AbuseCase::factory()->create();

    CaseAction::create([
        'case_id' => $case->id,
        'action_type' => ActionType::Opened,
        'note' => 'Case opened',
        'created_at' => now(),
    ]);

    CaseAction::create([
        'case_id' => $case->id,
        'action_type' => ActionType::NoteAdded,
        'note' => 'Test note',
        'created_at' => now(),
    ]);

    expect($case->actions)->toHaveCount(2);
});

test('case belongs to customer', function () {
    $customer = Customer::factory()->create();
    $case = AbuseCase::factory()->create(['customer_id' => $customer->id]);

    expect($case->customer->id)->toBe($customer->id);
});

test('report belongs to reporter', function () {
    $reporter = Reporter::factory()->create();
    $report = AbuseReport::factory()->create(['reporter_id' => $reporter->id]);

    expect($report->reporter->id)->toBe($reporter->id);
});

test('customer suspension history tracks correctly', function () {
    $customer = Customer::factory()->create();
    $case = AbuseCase::factory()->create(['customer_id' => $customer->id]);

    SuspensionHistory::create([
        'customer_id' => $customer->id,
        'case_id' => $case->id,
        'suspension_reason' => 'Auto-suspended: score 85',
        'suspended_at' => now(),
    ]);

    expect($customer->suspensionHistory)->toHaveCount(1);
    expect($customer->suspensionHistory->first()->suspension_reason)->toContain('score 85');
});
