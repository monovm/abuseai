<?php

use App\Enums\AbuseType;
use App\Events\CaseOpened;
use App\Events\CaseScored;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\Customer;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\CaseEngine\CaseCreatorService;
use Illuminate\Support\Facades\Event;

test('case is NOT created for IP not in our inventory', function () {
    Event::fake([CaseOpened::class, CaseScored::class]);

    $reporter = Reporter::factory()->create(['reputation_score' => 50]);

    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Spam,
        'target_ip' => '99.99.99.99', // NOT in our inventory
    ]);

    $creator = app(CaseCreatorService::class);
    $result = $creator->findOrCreateCase($report);

    expect($result)->toBeNull();
    expect(AbuseCase::count())->toBe(0);

    // Report should be tagged with skip reason
    $report->refresh();
    expect($report->metadata['skipped_reason'] ?? null)->toBe('ip_not_in_inventory');

    Event::assertNotDispatched(CaseOpened::class);
});

test('case IS created for IP in our inventory', function () {
    Event::fake([CaseOpened::class, CaseScored::class]);

    // Add IP to our inventory
    IpAddress::factory()->create(['ip_address' => '10.20.30.40', 'status' => 'active']);

    $reporter = Reporter::factory()->create(['reputation_score' => 50]);

    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Spam,
        'target_ip' => '10.20.30.40', // IN our inventory
    ]);

    $creator = app(CaseCreatorService::class);
    $case = $creator->findOrCreateCase($report);

    expect($case)->toBeInstanceOf(AbuseCase::class);
    expect($case->target_ip)->toBe('10.20.30.40');
    expect(AbuseCase::count())->toBe(1);

    Event::assertDispatched(CaseOpened::class);
});

test('case auto-links customer from IP inventory', function () {
    Event::fake([CaseOpened::class, CaseScored::class]);

    $customer = Customer::factory()->create();

    IpAddress::factory()->create([
        'ip_address' => '10.20.30.50',
        'customer_id' => $customer->id,
        'status' => 'active',
    ]);

    $reporter = Reporter::factory()->create(['reputation_score' => 50]);

    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Phishing,
        'target_ip' => '10.20.30.50',
    ]);

    $creator = app(CaseCreatorService::class);
    $case = $creator->findOrCreateCase($report);

    expect($case->customer_id)->toBe($customer->id);
});

test('decommissioned IP does not create a case', function () {
    Event::fake([CaseOpened::class, CaseScored::class]);

    IpAddress::factory()->create([
        'ip_address' => '10.20.30.60',
        'status' => 'decommissioned',
    ]);

    $reporter = Reporter::factory()->create(['reputation_score' => 50]);

    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Malware,
        'target_ip' => '10.20.30.60',
    ]);

    $creator = app(CaseCreatorService::class);
    $result = $creator->findOrCreateCase($report);

    expect($result)->toBeNull();
});
