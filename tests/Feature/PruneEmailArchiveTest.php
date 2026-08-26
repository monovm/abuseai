<?php

use App\Enums\CaseStatus;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Two archive months: one comfortably past any retention window, one
    // that is the current month and must survive.
    $this->oldMonth = 'emails/2020/01';
    $this->newMonth = 'emails/'.date('Y/m');
});

it('deletes archives older than the retention window and keeps recent ones', function () {
    Storage::disk('local')->put($this->oldMonth.'/old.eml', 'raw old email');
    Storage::disk('local')->put($this->newMonth.'/new.eml', 'raw new email');

    $this->artisan('abuse:prune-emails', ['--days' => 30])
        ->assertSuccessful();

    Storage::disk('local')->assertMissing($this->oldMonth.'/old.eml');
    Storage::disk('local')->assertExists($this->newMonth.'/new.eml');
});

it('leaves everything in place on a dry run', function () {
    Storage::disk('local')->put($this->oldMonth.'/old.eml', 'raw old email');

    $this->artisan('abuse:prune-emails', ['--days' => 30, '--dry-run' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists($this->oldMonth.'/old.eml');
});

it('keeps aged archives still attached to a live case', function () {
    $path = $this->oldMonth.'/live.eml';
    Storage::disk('local')->put($path, 'raw old email');

    $case = AbuseCase::factory()->create(['status' => CaseStatus::Open]);
    AbuseReport::factory()->create([
        'case_id' => $case->id,
        'attachment_paths' => [$path],
    ]);

    $this->artisan('abuse:prune-emails', ['--days' => 30])
        ->assertSuccessful();

    Storage::disk('local')->assertExists($path);
});

it('prunes archives attached to a closed case', function () {
    $path = $this->oldMonth.'/closed.eml';
    Storage::disk('local')->put($path, 'raw old email');

    $case = AbuseCase::factory()->create(['status' => CaseStatus::Closed]);
    AbuseReport::factory()->create([
        'case_id' => $case->id,
        'attachment_paths' => [$path],
    ]);

    $this->artisan('abuse:prune-emails', ['--days' => 30])
        ->assertSuccessful();

    Storage::disk('local')->assertMissing($path);
});

it('prunes live-case archives when forced', function () {
    $path = $this->oldMonth.'/live.eml';
    Storage::disk('local')->put($path, 'raw old email');

    $case = AbuseCase::factory()->create(['status' => CaseStatus::Open]);
    AbuseReport::factory()->create([
        'case_id' => $case->id,
        'attachment_paths' => [$path],
    ]);

    $this->artisan('abuse:prune-emails', ['--days' => 30, '--force-open' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertMissing($path);
});

it('leaves extracted evidence alone unless asked', function () {
    Storage::disk('local')->put('evidence/2020/01/report.pdf', '%PDF-1.4');

    $this->artisan('abuse:prune-emails', ['--days' => 30])
        ->assertSuccessful();

    Storage::disk('local')->assertExists('evidence/2020/01/report.pdf');

    $this->artisan('abuse:prune-emails', ['--days' => 30, '--include-evidence' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertMissing('evidence/2020/01/report.pdf');
});

it('refuses a zero-day retention window', function () {
    Storage::disk('local')->put($this->oldMonth.'/old.eml', 'raw old email');

    $this->artisan('abuse:prune-emails', ['--days' => 0])
        ->assertFailed();

    Storage::disk('local')->assertExists($this->oldMonth.'/old.eml');
});
