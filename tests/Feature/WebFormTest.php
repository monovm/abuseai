<?php

use App\Events\ReportReceived;
use App\Models\AbuseReport;
use App\Models\Reporter;
use Illuminate\Support\Facades\Event;

test('abuse report form loads', function () {
    $this->get('/report')->assertStatus(200)->assertSee('Report Abuse');
});

test('submitting abuse form creates report and reporter', function () {
    Event::fake([ReportReceived::class]);

    $this->post('/report', [
        'abuse_type' => 'spam',
        'reporter_name' => 'Test Reporter',
        'reporter_email' => 'test@example.com',
        'target_ip' => '192.168.1.1',
        'description' => 'This IP is sending spam emails to our network repeatedly.',
    ])->assertRedirect('/report');

    expect(Reporter::where('email', 'test@example.com')->exists())->toBeTrue();
    expect(AbuseReport::where('target_ip', '192.168.1.1')->exists())->toBeTrue();

    Event::assertDispatched(ReportReceived::class);
});

test('form validation rejects missing required fields', function () {
    $this->post('/report', [])
        ->assertSessionHasErrors(['abuse_type', 'reporter_name', 'reporter_email', 'description']);
});

test('form validation rejects invalid IP', function () {
    $this->post('/report', [
        'abuse_type' => 'spam',
        'reporter_name' => 'Test',
        'reporter_email' => 'test@example.com',
        'target_ip' => 'not-an-ip',
        'description' => 'This is a valid description for the report.',
    ])->assertSessionHasErrors(['target_ip']);
});

test('form validation rejects short description', function () {
    $this->post('/report', [
        'abuse_type' => 'spam',
        'reporter_name' => 'Test',
        'reporter_email' => 'test@example.com',
        'description' => 'Short',
    ])->assertSessionHasErrors(['description']);
});
