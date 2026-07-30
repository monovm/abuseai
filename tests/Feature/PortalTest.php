<?php

use App\Enums\CaseStatus;
use App\Events\ReportReceived;
use App\Models\AbuseCase;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

test('portal case list requires auth', function () {
    $this->get('/portal/cases')->assertRedirect('/login');
});

test('portal case list loads for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/portal/cases')
        ->assertStatus(200);
});

test('customer can submit appeal for actioned case', function () {
    Queue::fake();

    $user = User::factory()->create(['email' => 'customer@test.com']);
    $customer = Customer::factory()->create(['email' => 'customer@test.com']);

    $case = AbuseCase::factory()->create([
        'customer_id' => $customer->id,
        'status' => CaseStatus::Actioned,
    ]);

    $this->actingAs($user)
        ->post("/portal/cases/{$case->id}/appeal", [
            'appeal_text' => 'I believe this suspension was made in error. Our server was compromised and we have taken corrective action.',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('case_actions', [
        'case_id' => $case->id,
        'action_type' => 'appeal_received',
    ]);
});

test('appeal validation rejects short text', function () {
    $user = User::factory()->create(['email' => 'customer2@test.com']);
    $customer = Customer::factory()->create(['email' => 'customer2@test.com']);

    $case = AbuseCase::factory()->create([
        'customer_id' => $customer->id,
        'status' => CaseStatus::Actioned,
    ]);

    $this->actingAs($user)
        ->post("/portal/cases/{$case->id}/appeal", [
            'appeal_text' => 'Too short',
        ])
        ->assertSessionHasErrors(['appeal_text']);
});
