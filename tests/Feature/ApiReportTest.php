<?php

use App\Events\ReportReceived;
use App\Models\AbuseReport;
use App\Models\Reporter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

test('API rejects requests without API key', function () {
    $this->postJson('/api/v1/reports', [
        'abuse_type' => 'spam',
        'description' => 'Test spam report via API endpoint.',
    ])->assertStatus(401);
});

test('API rejects invalid API key', function () {
    $this->postJson('/api/v1/reports', [
        'abuse_type' => 'spam',
        'description' => 'Test spam report via API endpoint.',
    ], ['X-API-Key' => 'invalid-key'])->assertStatus(403);
});

test('API accepts valid report with API key', function () {
    Event::fake([ReportReceived::class]);

    $reporter = Reporter::factory()->create([
        'api_key' => Hash::make('test-api-key-123'),
    ]);

    $this->postJson('/api/v1/reports', [
        'abuse_type' => 'phishing',
        'target_ip' => '10.0.0.1',
        'description' => 'Phishing page detected on this IP address serving fake login page.',
    ], ['X-API-Key' => 'test-api-key-123'])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'message']);

    expect(AbuseReport::where('target_ip', '10.0.0.1')->exists())->toBeTrue();
    Event::assertDispatched(ReportReceived::class);
});

test('bulk API accepts multiple reports', function () {
    Event::fake([ReportReceived::class]);

    $reporter = Reporter::factory()->create([
        'api_key' => Hash::make('bulk-key-456'),
    ]);

    $this->postJson('/api/v1/reports/bulk', [
        'reports' => [
            ['abuse_type' => 'spam', 'description' => 'Spam report one from bulk test submission.', 'target_ip' => '1.1.1.1'],
            ['abuse_type' => 'malware', 'description' => 'Malware report two from bulk test submission.', 'target_ip' => '2.2.2.2'],
        ],
    ], ['X-API-Key' => 'bulk-key-456'])
        ->assertStatus(201)
        ->assertJson(['count' => 2]);

    Event::assertDispatched(ReportReceived::class, 2);
});

test('webhook endpoint accepts signed payload from known provider', function () {
    Illuminate\Support\Facades\Queue::fake();
    config(['abusedesk.webhooks.abuseipdb.secret' => 'test-secret']);

    $payload = ['ip' => '192.168.1.1', 'categories' => [9]];
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, 'test-secret');

    $this->call(
        'POST',
        '/api/v1/webhook/abuseipdb',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $signature],
        $body,
    )->assertStatus(202);
});

test('webhook endpoint rejects unsigned payload when secret is configured', function () {
    config(['abusedesk.webhooks.abuseipdb.secret' => 'test-secret']);

    $this->postJson('/api/v1/webhook/abuseipdb', [
        'ip' => '192.168.1.1',
        'categories' => [9],
    ])->assertStatus(403);
});

test('webhook endpoint rejects when no secret is configured', function () {
    // Forgotten env var must not silently disable verification.
    config(['abusedesk.webhooks.abuseipdb.secret' => null]);

    $this->postJson('/api/v1/webhook/abuseipdb', [
        'ip' => '192.168.1.1',
    ])->assertStatus(403);
});

test('webhook endpoint rejects unknown provider', function () {
    $this->postJson('/api/v1/webhook/unknown', [])
        ->assertStatus(404);
});
