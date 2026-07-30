# Contributing to Abuse AI

Thanks for your interest in improving Abuse AI. This document covers how to
set up a development environment, the conventions we follow, and how to get
changes merged.

By contributing, you agree your contributions will be licensed under the
[Apache License 2.0](LICENSE).

## Reporting Bugs / Requesting Features

- **Bugs**: open an issue using the *Bug report* template
  ([.github/ISSUE_TEMPLATE/bug_report.yml](.github/ISSUE_TEMPLATE/bug_report.yml)).
  Include the commit hash you reproduced against, your environment (PHP
  version, DB driver, queue driver, AI provider), and the smallest reproducer
  you can.
- **Features**: open an issue using the *Feature request* template before
  writing the code. Big PRs opened without prior discussion may be closed.
- **Security**: do **not** open a public issue. See [SECURITY.md](SECURITY.md)
  for the private disclosure path.

## Development Setup

```bash
git clone https://github.com/monovm/abuseai.git
cd abuseai

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate

# SQLite is the easiest dev DB; switch to pgsql/mysql when you need
# features your target deploy uses.
touch database/database.sqlite
php artisan migrate --seed

php artisan serve
```

Run the full dev stack (server + queue + log tail + Vite) with:

```bash
composer dev
```

Default seeded admins (rotate immediately on any non-throwaway environment):

| Email | Password |
|-------|----------|
| `admin@abuseai.io`  | `password` |
| `editor@abuseai.io` | `password` |

## Conventions

### Commits

- One logical change per commit.
- Subject line in the imperative ("Fix dedup race", not "Fixed").
- Wrap bodies at ~72 chars and explain *why*, not *what*.

### Code style

- PHP: `vendor/bin/pint` (Laravel Pint, included as a dev dependency).
  CI currently runs Pint informationally — it'll be a hard gate after the
  one-time backlog is cleared.
- Prefer explicit over clever. The pipeline already has enough surprises.

### Tests

- Pest is the test framework. Write feature tests for routes and jobs, unit
  tests for services and enums.
- Run tests with:
  ```bash
  composer test
  ```
- New behaviour without a test will block the PR unless there's a clear
  reason it can't be tested.

### Database changes

- New migrations only — never edit a migration that's been merged.
- Use `uuid` primary keys for new tables to match the rest of the schema.
- Add the column to the model's `$fillable` and `$casts` in the same PR.
- For columns that store encrypted values, use `text`/`mediumText`, not
  `json`/`jsonb` — Laravel's `encrypted` cast emits a base64 blob that
  fails JSON validation.

### AI changes

- Every Claude/OpenAI call must log the prompt + response to `audit_logs`.
- AI failures must degrade gracefully — they may not block the case
  pipeline.
- New prompts go in `config/ai.php` or the relevant service, not inline
  in jobs.

### Queue jobs

- All jobs that touch external APIs (WHMCS, Virtualizor, Anthropic) must
  declare a sensible `$timeout` and `$tries`.
- Jobs that mutate per-IP / per-case state should use
  `WithoutOverlapping` middleware to prevent duplicate work under load.

## Pull Request Process

1. Fork the repo and branch from `main` (e.g. `fix/dedup-race`,
   `feat/snds-collector`).
2. Make your changes with tests + Pint clean.
3. Run `composer test` and `vendor/bin/pint --test` locally.
4. Open a PR using the template
   ([.github/pull_request_template.md](.github/pull_request_template.md));
   link the issue if one exists.
5. CI must pass before review.
6. Squash-merge on green review. Maintainers will rebase if `main` has
   moved.

## What Lives Where

A quick map for new contributors:

```
app/Console/Commands/    Artisan commands (poll-feeds, poll-mailbox, etc.)
app/Events/              ReportReceived, CaseOpened, CaseScored
app/Listeners/           HandleReportReceived, HandleCaseScored
app/Http/Controllers/
  Api/                   Reporter API + webhook receivers
  Admin/                 Case management UI controllers
  WebForm/               Public abuse report form
app/Jobs/
  Ingestion/             Feed pollers, email parsers, webhook processors
  Processing/            Enrichment, dedup
  AI/                    Triage, rescore, draft, pattern-detect
  Automation/            Suspend, block-IP, escalate, link-customer
app/Livewire/Admin/      Livewire components for the admin UI
app/Services/
  AI/                    Claude / OpenAI provider wrappers
  CaseEngine/            Case creator, scorer, router
  Enrichment/            AbuseIPDB, VirusTotal, Shodan, DNSBL, SNDS
  Infrastructure/        WHMCS, Virtualizor, SolusVM, Proxmox, Blesta, HostBill
  Ingestion/             Feed pollers, dedup, normalizer
config/abusedesk.php     All custom config (thresholds, feeds, scoring)
config/ai.php            AI provider config + prompt templates
database/migrations/     Schema (UUID PKs, jsonb metadata)
database/seeders/        Demo data for dev / first-run
deploy/                  supervisor/cron/nginx examples for production
docs/                    Architecture, API, admin guide, deployment
```

## Questions

Open a [Discussion](https://github.com/monovm/abuseai/discussions) or
start a draft PR — we'd rather see code than wait for the perfect issue.
