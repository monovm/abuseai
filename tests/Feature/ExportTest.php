<?php

use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\Reporter;
use App\Models\User;

it('exports cases as CSV for admin users', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    AbuseCase::factory()->count(3)->create();

    $response = $this->actingAs($user)->get('/admin/export/cases');

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('exports reports as CSV for admin users', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $reporter = Reporter::factory()->create();
    AbuseReport::factory()->count(3)->create(['reporter_id' => $reporter->id]);

    $response = $this->actingAs($user)->get('/admin/export/reports');

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('blocks non-admin users from exporting', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin/export/cases');

    $response->assertStatus(403);
});
