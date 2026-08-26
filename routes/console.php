<?php

use Illuminate\Support\Facades\Schedule;

// All scheduled commands MUST be ->runInBackground() — schedule:run otherwise
// runs them inline one after another, so a slow command (the reputation scan
// is the worst offender at ~20-40 min for a 500-IP run) holds up the bash
// loop's next tick and blocks every other tick during that window. Symptom
// when this is forgotten: emails sit in INBOX for 30+ min, last_polled_at
// frozen at the timestamp of the slow command's start.

// Poll all email connections every 2 minutes (fetch, create cases, send ACK replies)
Schedule::command('abuse:poll-mailbox')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Poll AbuseIPDB feed every 15 minutes (ingest new reports from AbuseIPDB)
Schedule::command('abuse:poll-feeds')
    ->everyFifteenMinutes()
    ->runInBackground();

// Reputation scan: collect data from all sources, update reputation, auto-create cases
// Runs every 6 hours, scans IPs not checked in 6 hours, limit 500 per run (rate limits).
// MUST run in background — this is the command that historically blocked the
// scheduler for 30+ minutes when run inline.
Schedule::command('abuse:scan-reputation --stale=6 --create-cases --limit=500')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground();

// Prune aged-out raw email archives (emails/Y/m/*.eml) nightly. Without this
// the intake archive grows without bound — it reached 40GB before the command
// existed. Files attached to live cases are kept regardless of age.
Schedule::command('abuse:prune-emails')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();
