<?php

use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\InboxNotification;
use App\Models\Reporter;

test('targetUrl points at case detail for case follow-up', function () {
    $case = AbuseCase::factory()->create();
    $note = InboxNotification::create([
        'type' => InboxNotification::TYPE_CASE_FOLLOWUP,
        'case_id' => $case->id,
        'title' => 'Reporter follow-up',
    ]);

    expect($note->targetUrl())->toBe("/admin/cases/{$case->id}");
});

test('targetUrl falls back to reports queue for case-less report notification', function () {
    $reporter = Reporter::factory()->create();
    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'case_id' => null,
    ]);

    $note = InboxNotification::create([
        'type' => InboxNotification::TYPE_REPORT_FOLLOWUP,
        'report_id' => $report->id,
        'title' => 'Reporter follow-up on report',
    ]);

    expect($note->targetUrl())->toBe('/admin/reports');
});

test('unread scope excludes read notifications', function () {
    InboxNotification::create([
        'type' => InboxNotification::TYPE_CASE_FOLLOWUP,
        'title' => 'one',
    ]);
    InboxNotification::create([
        'type' => InboxNotification::TYPE_CASE_FOLLOWUP,
        'title' => 'two',
        'read_at' => now(),
    ]);

    expect(InboxNotification::unread()->count())->toBe(1);
});
