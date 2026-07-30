<?php

use App\Models\AbuseCase;
use App\Models\Customer;
use App\Models\User;

test('admin case index requires auth', function () {
    $this->get('/admin/cases')->assertRedirect('/login');
});

test('admin case index loads for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/cases')
        ->assertStatus(200);
});

test('admin case detail loads for authenticated user', function () {
    $user = User::factory()->create();
    $case = AbuseCase::factory()->create();

    $this->actingAs($user)
        ->get("/admin/cases/{$case->id}")
        ->assertStatus(200);
});

test('admin customer list loads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/customers')
        ->assertStatus(200);
});

test('admin customer detail loads', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $this->actingAs($user)
        ->get("/admin/customers/{$customer->id}")
        ->assertStatus(200);
});

test('admin analytics loads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/analytics')
        ->assertStatus(200);
});

test('admin reporters page loads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/reporters')
        ->assertStatus(200);
});

test('admin rules page loads', function () {
    // /admin/rules is gated with role:admin (settings page) — assign the
    // role explicitly. Plain auth users get 403.
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/admin/rules')
        ->assertStatus(200);
});

test('admin templates page loads', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/admin/templates')
        ->assertStatus(200);
});
