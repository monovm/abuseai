<?php

use App\Models\User;

test('IP inventory page requires auth', function () {
    $this->get('/admin/ips')->assertRedirect('/login');
});

test('IP inventory page loads for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/ips')
        ->assertStatus(200);
});
