<?php

use App\Enums\ActionType;
use App\Enums\CaseStatus;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\CaseAction;
use Illuminate\Support\Facades\DB;
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

it('keeps a reply .eml threaded onto a live case as a case action', function () {
    $path = $this->oldMonth.'/reply.eml';
    Storage::disk('local')->put($path, 'raw reply');

    $case = AbuseCase::factory()->create(['status' => CaseStatus::Open]);
    CaseAction::create([
        'case_id' => $case->id,
        'action_type' => ActionType::NoteAdded,
        'payload' => ['type' => 'email_reply', 'attachment' => $path],
        'note' => 'Email reply from reporter',
        'created_at' => now(),
    ]);

    $this->artisan('abuse:prune-emails', ['--days' => 30])
        ->assertSuccessful();

    Storage::disk('local')->assertExists($path);
});

it('prunes a reply .eml once its case is closed', function () {
    $path = $this->oldMonth.'/reply.eml';
    Storage::disk('local')->put($path, 'raw reply');

    $case = AbuseCase::factory()->create(['status' => CaseStatus::Closed]);
    CaseAction::create([
        'case_id' => $case->id,
        'action_type' => ActionType::NoteAdded,
        'payload' => ['type' => 'email_reply', 'attachment' => $path],
        'note' => 'Email reply from reporter',
        'created_at' => now(),
    ]);

    $this->artisan('abuse:prune-emails', ['--days' => 30])
        ->assertSuccessful();

    Storage::disk('local')->assertMissing($path);
});

it('keeps a reporter follow-up .eml recorded in report metadata', function () {
    $path = $this->oldMonth.'/followup.eml';
    Storage::disk('local')->put($path, 'raw follow-up');

    $case = AbuseCase::factory()->create(['status' => CaseStatus::Investigating]);
    AbuseReport::factory()->create([
        'case_id' => $case->id,
        'attachment_paths' => null,
        'metadata' => ['followups' => [['from' => 'x@y.z', 'attachment' => $path]]],
    ]);

    $this->artisan('abuse:prune-emails', ['--days' => 30])
        ->assertSuccessful();

    Storage::disk('local')->assertExists($path);
});

it('never touches files outside the archive prefixes', function () {
    Storage::disk('local')->put('ai_provider_settings.json', '{}');
    Storage::disk('local')->put('email_transport_settings.json', '{}');
    Storage::disk('local')->put('imports/2020/01/import.log', 'old import log');

    $this->artisan('abuse:prune-emails', ['--days' => 30, '--include-evidence' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists('ai_provider_settings.json');
    Storage::disk('local')->assertExists('email_transport_settings.json');
    Storage::disk('local')->assertExists('imports/2020/01/import.log');
});

it('never sorts over the heavy json columns', function () {
    // Regression: chunkById() appends ORDER BY id, and MySQL's filesort
    // buffers every selected column. Selecting metadata/payload (multi-KB
    // JSON) alongside that ORDER BY overran sort_buffer_size on production
    // and killed the run with "1038 Out of sort memory". Any query that
    // sorts must select ids only.
    $case = AbuseCase::factory()->create(['status' => CaseStatus::Open]);
    AbuseReport::factory()->create([
        'case_id' => $case->id,
        'attachment_paths' => [$this->oldMonth.'/a.eml'],
        'metadata' => ['followups' => [['attachment' => $this->oldMonth.'/b.eml']]],
    ]);
    CaseAction::create([
        'case_id' => $case->id,
        'action_type' => ActionType::NoteAdded,
        'payload' => ['attachment' => $this->oldMonth.'/c.eml'],
        'note' => 'reply',
        'created_at' => now(),
    ]);

    $ordered = [];
    DB::listen(function ($query) use (&$ordered) {
        if (stripos($query->sql, 'order by') !== false) {
            $ordered[] = $query->sql;
        }
    });

    $this->artisan('abuse:prune-emails', ['--days' => 30])->assertSuccessful();

    expect($ordered)->not->toBeEmpty();

    // Only the SELECT list matters — filesort buffers selected columns, not
    // columns named in WHERE predicates.
    foreach ($ordered as $sql) {
        preg_match('/^select\\s+(.*?)\\s+from\\s/is', $sql, $m);
        $selectList = $m[1] ?? $sql;

        expect($selectList)->not->toContain('metadata')
            ->and($selectList)->not->toContain('attachment_paths')
            ->and($selectList)->not->toContain('payload');
    }
});

it('still protects every live-case path after the batching change', function () {
    $a = $this->oldMonth.'/a.eml';
    $b = $this->oldMonth.'/b.eml';
    $c = $this->oldMonth.'/c.eml';
    foreach ([$a, $b, $c] as $p) {
        Storage::disk('local')->put($p, 'raw');
    }

    $case = AbuseCase::factory()->create(['status' => CaseStatus::Open]);
    AbuseReport::factory()->create([
        'case_id' => $case->id,
        'attachment_paths' => [$a],
        'metadata' => ['followups' => [['attachment' => $b]]],
    ]);
    CaseAction::create([
        'case_id' => $case->id,
        'action_type' => ActionType::NoteAdded,
        'payload' => ['attachment' => $c],
        'note' => 'reply',
        'created_at' => now(),
    ]);

    $this->artisan('abuse:prune-emails', ['--days' => 30])->assertSuccessful();

    Storage::disk('local')->assertExists($a);
    Storage::disk('local')->assertExists($b);
    Storage::disk('local')->assertExists($c);
});
