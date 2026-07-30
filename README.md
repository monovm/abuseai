# Abuse AI

**AI-powered abuse management platform for hosting companies and ISPs.**

[![CI](https://github.com/monovm/abuseai/actions/workflows/ci.yml/badge.svg)](https://github.com/monovm/abuseai/actions/workflows/ci.yml)
[![Docker Image](https://github.com/monovm/abuseai/actions/workflows/docker-publish.yml/badge.svg)](https://github.com/monovm/abuseai/actions/workflows/docker-publish.yml)
[![Docker Pulls](https://img.shields.io/docker/pulls/monovmcom/abuseai.io)](https://hub.docker.com/r/monovmcom/abuseai.io)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg)](https://php.net)

Receives, triages, scores, and actions abuse reports with AI at every key step. Built with Laravel, Livewire, and Claude/OpenAI.

### Deploy in one click

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/monovm/abuseai)
[![Deploy to Heroku](https://www.herokucdn.com/deploy/button.svg)](https://heroku.com/deploy?template=https://github.com/monovm/abuseai)
[![Deploy to DigitalOcean](https://www.deploytodo.com/do-btn-blue.svg)](https://cloud.digitalocean.com/apps/new?repo=https://github.com/monovm/abuseai/tree/main)

Each button builds the same [`Dockerfile`](Dockerfile) that Docker Compose uses
and provisions the app, a queue worker, and PostgreSQL. Render and Heroku add
Redis too; on DigitalOcean you supply it (details in
[Deploy to the cloud](#deploy-to-the-cloud)). Prefer your own host?
`docker compose up -d` — see [Installation](#installation).

> **Built by [MonoVM](https://monovm.com)** — main sponsor of this project.

> **License:** [Apache License 2.0](LICENSE) — open source. You may freely use,
> copy, modify, and redistribute this software for any purpose, including
> commercial and managed-service use, subject to the conditions of the license.
> See [LICENSE](LICENSE) for the full terms.
>
> Reporting a security issue? See [SECURITY.md](SECURITY.md).
> Want to contribute? See [CONTRIBUTING.md](CONTRIBUTING.md).

--- 

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Pipeline Flow](#pipeline-flow)
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage Guide](#usage-guide)
- [IP Inventory & Reputation](#ip-inventory--reputation)
- [Email System](#email-system)
- [API Reference](#api-reference)
- [Artisan Commands](#artisan-commands)
- [Scheduler & Automation](#scheduler--automation)
- [Tech Stack](#tech-stack)
- [Contributing](#contributing)
- [License](#license)

---

## Overview

Abuse AI is a centralized abuse desk that:

1. **Ingests** reports from email, API, webhooks, web form, and feeds
2. **Classifies** them with AI (type, severity, noise filtering, translation)
3. **Matches** target IPs against your IP inventory
4. **Creates cases** with severity scoring and SLA tracking
5. **Enriches** with threat intelligence (AbuseIPDB, VirusTotal, Shodan, DNSBL, Microsoft SNDS)
6. **Automates** actions (suspend, block, escalate, notify)
7. **Communicates** via branded email (Mandrill) with AI-drafted replies
8. **Monitors** IP reputation across 6 data sources continuously

---

## Architecture

```
┌────────────────────────────────────────────────────────────────┐
│                        INGESTION                               │
│  Email Inbox (POP3) │ Web Form │ REST API │ Webhooks │ Feeds  │
└──────────────┬─────────────────────────────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────────────────┐
│                      AI TRIAGE (Layer 2)                       │
│  Classify │ Parse IOCs │ Noise Filter │ Translate (multi-lang) │
└──────────────┬─────────────────────────────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────────────────┐
│                   IP INVENTORY CHECK                           │
│  Is target IP in our network? → Match to customer/server       │
└──────────────┬─────────────────────────────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────────────────┐
│               PROCESSING & ENRICHMENT (Layer 3)                │
│  AbuseIPDB │ VirusTotal │ Shodan │ DNSBL │ SNDS │ Dedup      │
└──────────────┬─────────────────────────────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────────────────┐
│                    CASE ENGINE (Layer 4)                        │
│  Create/Link Case │ Severity Scorer │ Aggregator │ SLA Router │
└──────────────┬─────────────────────────────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────────────────┐
│                 AI INTELLIGENCE (Layer 5)                       │
│  Re-scorer │ Pattern Detector │ Evidence Analyser │ Profiler  │
└──────────────┬─────────────────────────────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────────────────┐
│                   AUTOMATION (Layer 6)                          │
│  Auto-suspend │ Block IP │ Escalate │ Notify │ Rules Engine   │
└──────────────┬─────────────────────────────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────────────────┐
│                AI COMMUNICATIONS (Layer 7)                      │
│  ACK Replies │ Customer Notices │ Appeal Handler │ Summariser │
└────────────────────────────────────────────────────────────────┘
```

---

## Pipeline Flow

### When an email arrives:

```
1. POP3 poller fetches email (every 2 minutes)
2. Check: is this a reply to an existing case?
   YES → Add as follow-up note on existing case (no new case)
3. Check: is this from our own address / auto-reply / OOO?
   YES → Skip and delete from server
4. Extract sender → create/find Reporter
5. Guess abuse type from keywords (spam, phishing, law_enforcement, etc.)
6. Extract all IPs from body → find first IP in our inventory
7. Create abuse report
8. Dedup check (3-level: exact, target, content hash)
9. Create or link to existing case
10. Send ACK reply via Mandrill (branded, threaded)
11. Delete email from server
```

### When a web form / API report arrives:

```
1. Validate input
2. Create Reporter (or find existing)
3. Create abuse report
4. Dedup check → Create/link case
5. Return case number
```

### Reputation scan (every 6 hours):

```
1. For each IP in inventory (batch of 500):
   - Query AbuseIPDB (confidence score)
   - Query DNSBL (5 blacklists)
   - Query VirusTotal (malicious detections)
   - Query Microsoft SNDS (spam traps, filter status)
   - Query Shodan (vulnerabilities)
   - Count open cases
2. Calculate reputation score (100 = clean, 0 = worst)
3. If score < 30 → auto-create ONE case
4. If score recovers > 50 → reset flag, allow new case later
```

---

## Features

### Case Management
- Case queue with filter/sort by status, type, severity, search
- Case detail: reports, timeline, threat intel, AI panel, sent emails
- Manual case creation with IP inventory lookup
- Status workflow: Open → Investigating → Actioned → Resolved → Closed
- SLA deadlines per severity level

### 12 Abuse Types
| Type | Weight | Auto-detected from |
|------|--------|--------------------|
| Spam | 2 | spam, uce, unsolicited, bulk mail |
| Phishing | 4 | phish |
| Malware | 5 | malware, virus, trojan, ransomware |
| DDoS | 6 | ddos, denial of service, amplification |
| CSAM | 10 | csam, child (immediate escalation) |
| Copyright / DMCA | 2 | copyright, dmca, infringement |
| Fraud / Scam | 4 | fraud, scam, identity theft |
| Law Enforcement | 8 | police, criminal, court order, subpoena, cybercrime |
| Brute Force | 3 | brute force, port scan |
| Intrusion / Hacking | 6 | intrusion, hacking, exploit |
| Botnet / C2 | 7 | botnet, c2, command and control |
| Other | 1 | fallback |

### Severity Scoring Formula
```
score = base_weight[type]
      × log10(report_count + 1) × 10
      × (avg_reporter_reputation / 100)
      × recurrence_factor

score = min(score, 100)
```

Action thresholds:
- Score ≥ 20 → Flag for review
- Score ≥ 50 → Warn customer
- Score ≥ 80 → Auto-suspend

### AI Integration (Claude / OpenAI)
- **Triage:** Classify abuse type, extract IOCs, filter noise, translate non-English
- **Intelligence:** Re-score severity, detect patterns, analyze evidence, profile customers
- **Communications:** Draft reporter replies, customer notices, handle appeals, summarize cases
- Switch between Claude and OpenAI from admin settings
- All AI calls logged in audit trail

### IP Inventory
- Add IPs individually, by CIDR range, or dash range
- Bulk import (CSV, one per line)
- Tags: server name, datacenter, rack, customer link
- Status: active, reserved, decommissioned
- Only IPs in active inventory create cases from reports

### IP Reputation System (6 Collectors)
| Collector | Source | Max Penalty | Checks |
|-----------|--------|-------------|--------|
| AbuseIPDB | API | -50 pts | Abuse confidence score |
| DNSBL | DNS | -40 pts | CBL, PSBL, Spamhaus ZEN, Barracuda, SpamCop |
| VirusTotal | API | -30 pts | Malicious detection ratio |
| Microsoft SNDS | API | -30 pts | Spam traps, filter status, blocked |
| Shodan | API | -25 pts | Known CVE vulnerabilities |
| Open Cases | DB | -20 pts | Existing unresolved cases |

Score thresholds:
- 80-100: Good (green)
- 50-79: Fair (yellow)
- 30-49: Poor (orange)
- <30: Critical (red) → auto-create case

### Multi-Brand Email
- Multiple brands (each with its own identity, e.g., "Acme Hosting", "Brand B")
- Each brand: from name, from email, signature, footer, logo
- Per-brand Mandrill API key
- Multiple POP3/IMAP connections, each linked to a brand
- Reply from case detail with brand selector
- Email threading (In-Reply-To headers)

### Email Loop Prevention
- Skip emails from our own addresses
- Detect replies to our ACK emails → add as follow-up (no new case)
- Detect ISP auto-forward quoting our content → link to existing case
- Skip auto-replies, OOO, delivery failures, mailer-daemon
- RFC 3834 Auto-Submitted header detection
- Content dedup (strips our template, checks if meaningful content remains)

### Automation Rules
- Configurable trigger events: report_received, score_changed, time_elapsed
- Conditions: field operators (=, !=, >, >=, <, <=, in)
- Actions: suspend, block_ip, escalate, notify (email/slack/pagerduty)
- Priority ordering, abuse type filtering, minimum score threshold

### Audit Trail
- Every state change logged (old/new values)
- Every AI call logged (prompt + response, provider + model)
- Entity-based filtering (AbuseCase, AbuseReport, Customer, AI)
- Actor tracking (who did what)

---

## Installation

### Run with Docker (recommended)

The fastest way to get the full stack — web UI, queue workers, scheduler,
PostgreSQL, and Redis — running:

```bash
git clone https://github.com/monovm/abuseai.git
cd abuseai
cp .env.docker.example .env          # then edit DB_PASSWORD + API keys
docker compose up -d
```

Open <http://localhost:8000>. To start with an admin login already seeded:

```bash
docker compose -f docker-compose.yml -f docker-compose.demo.yml up -d
```

Prebuilt multi-arch images are on [Docker Hub](https://hub.docker.com/r/monovmcom/abuseai.io)
(`monovmcom/abuseai.io`) and GHCR (`ghcr.io/monovm/abuseai`). See
[`docs/docker.md`](docs/docker.md) for the full guide.

### Deploy to the cloud

The one-click buttons are at the [top of this README](#deploy-in-one-click).
What each target gives you:

- **Render** — fully one-click: app, worker, PostgreSQL, and Redis all come
  from [`render.yaml`](render.yaml). Paste an `APP_KEY` when prompted.
- **Heroku** — fully one-click via [`app.json`](app.json) +
  [`heroku.yml`](heroku.yml): provisions Heroku Postgres and Heroku Key-Value
  Store, migrates in the release phase, seeds an admin account after deploy.
  Both dynos are paid — Heroku has no free tier.
- **DigitalOcean** — [`.do/deploy.template.yaml`](.do/deploy.template.yaml)
  provisions the app, worker, and a dev PostgreSQL. **One manual prerequisite:**
  App Platform's dev databases are PostgreSQL-only, so create a managed Valkey
  database first and paste its connection string as `REDIS_URL` when the button
  prompts. Horizon and report de-duplication both require Redis.
- **Railway** — Dockerfile deploy via [`railway.json`](railway.json); add
  PostgreSQL and Redis from the dashboard.
- **Coolify** / Dokku / any Docker host — runs the bundled
  `docker-compose.yml` as-is.

Step-by-step instructions for each:
[`docs/docker.md`](docs/docker.md#one-click--paas-deploys).

### Requirements (manual install)
- PHP 8.4+
- MySQL 8.0+ or PostgreSQL 16+
- Composer 2.x
- Node.js 18+
- Redis (optional, for queue workers)

### Manual installation steps

```bash
# Clone
git clone https://github.com/monovm/abuseai.git
cd abuseai

# Install dependencies
composer install
npm install && npm run build

# Configure
cp .env.example .env
php artisan key:generate

# Edit .env with your database, mail, API keys
nano .env

# Migrate and seed
php artisan migrate
php artisan db:seed

# Start
php artisan serve
```

### Admin Accounts (seeder)

`php artisan db:seed` runs the `AdminSeeder`, which creates two admin
accounts (`admin@abuseai.io`, `editor@abuseai.io`). Each gets a **strong
random password that is printed once to the console** — copy it from the
seed output, it is not stored anywhere else:

```
========================================================
  Abuse AI — seeded admin account(s)
========================================================
  email:    admin@abuseai.io
  password: <random — copy this now>
```

To pin a known password instead (CI, throwaway demo), set
`SEED_ADMIN_PASSWORD` before seeding. Re-running the seeder never resets
an existing account's password.

### Hardening checklist (do this before going live)

1. **Webserver docroot must point at `public/`, not the project root.**
   `.env`, `app/`, `config/`, `storage/`, etc. live above the docroot in
   the safe layout — they cannot be served at all. See
   [`deploy/nginx.conf.example`](deploy/nginx.conf.example) for an nginx
   vhost. The bundled root `.htaccess` is for shared-hosting layouts
   where you can't change the docroot; it has explicit deny rules for
   `.env`, `composer.*`, dotfiles, and the source directories.
2. Set `APP_DEBUG=false` and `APP_ENV=production` in your real `.env`.
3. Set `SESSION_ENCRYPT=true` and `SESSION_SECURE_COOKIE=true`.
4. Skip the demo seeder in production (or, if you ran it, change the
   seeded admin password after first login — see *Admin Accounts* above).
5. Enforce HTTPS at the edge (nginx/Cloudflare). Add HSTS once you've
   confirmed every subdomain has a valid cert.
6. Make sure your reverse proxy is in `config/cloudflare.php` (or set
   `TRUSTED_PROXIES`) so `request()->ip()` returns the real client.

---

## Configuration

### .env Variables

```dotenv
# App
APP_NAME="Abuse AI"
APP_URL=https://abuseai.yourdomain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=abuseai
DB_USERNAME=root
DB_PASSWORD=

# AI Provider (claude or openai)
AI_PROVIDER=claude
ANTHROPIC_API_KEY=sk-ant-...
CLAUDE_MODEL=claude-sonnet-4-20250514
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o

# Email Sending (Mandrill / Mailchimp Transactional)
MANDRILL_KEY=your_mandrill_key

# POP3 Email Ingestion (or configure via /admin/brands)
POP3_HOST=mail.yourdomain.com
POP3_PORT=995
POP3_USERNAME=abuse@yourdomain.com
POP3_PASSWORD=
POP3_SSL=true

# Threat Intelligence & Reputation Collectors
ABUSEIPDB_KEY=your_key          # abuseipdb.com — free, 1000 checks/day
VIRUSTOTAL_KEY=your_key          # virustotal.com — free, 500 lookups/day
SHODAN_KEY=your_key              # shodan.io — free tier available
SNDS_KEY=your_key                # Microsoft SNDS (sendersupport.olc.protection.outlook.com/snds/)
```

> **Note on data sources:** Spamhaus, CBL, PSBL, Barracuda, and SpamCop data is collected via
> **DNS lookups** (DNSBL) — no API keys needed. Microsoft JMRP (Junk Mail Reporting Program) sends
> ARF-formatted emails to your abuse inbox — no webhook needed. SpamCop also sends reports via email.
> Google Postmaster data must be checked manually at postmaster.google.com.

### Roles & Permissions

| Role | Access |
|------|--------|
| **admin** | Full access: cases, settings, users, AI, rules |
| **agent** | Cases, reports, customers, IPs, AI triggers |
| **viewer** | Read-only: cases, reports, analytics |

---

## Usage Guide

### 1. Set Up Your IP Inventory

Go to `/admin/ips` and import your IP ranges:

```
# Single IP
192.168.1.1

# CIDR range (expands to individual IPs)
10.0.0.0/24

# Dash range
172.16.0.1-172.16.0.50

# CSV format (ip,server_name)
10.0.0.1,web-srv-01
10.0.0.2,mail-srv-01
```

### 2. Create Brands

Go to `/admin/brands`:
1. Create a brand (e.g., "Acme Hosting") with from email, signature, footer
2. Add a POP3/IMAP connection linked to that brand
3. Enable auto-import on the connection
4. Test the connection

### 3. Configure AI Provider

Go to `/admin/ai-settings`:
1. Select Claude or OpenAI
2. Choose model
3. Test connection

### 4. Start Processing

Cases are created automatically when:
- Emails arrive in configured POP3 inboxes (polled every 2 min)
- Reports submitted via web form at `/report`
- Reports received via API
- IP reputation drops below threshold

### 5. Work Cases

At `/admin/cases`:
1. Review incoming cases
2. View threat intelligence data
3. Use AI to draft replies
4. Reply directly via email (branded, threaded)
5. Change status, add notes
6. Close or escalate

---

## Data Collection Sources

### How Each Source Delivers Data

| Source | How We Get Data | API Key? | Creates Cases? |
|--------|----------------|----------|----------------|
| **Email reports** | POP3 polling (every 2 min) | No (POP3 credentials) | YES — directly |
| **SpamCop** | Sends email to your abuse@ inbox | No | YES — via email poll |
| **Microsoft JMRP** | Sends ARF email to abuse@ inbox | No | YES — via email poll |
| **AbuseIPDB feed** | API polling (every 15 min) | `ABUSEIPDB_KEY` | NO — reputation only |
| **AbuseIPDB check** | API per-IP lookup | `ABUSEIPDB_KEY` | NO — reputation only |
| **Spamhaus** | DNSBL DNS lookup | No | NO — reputation only |
| **CBL** | DNSBL DNS lookup | No | NO — reputation only |
| **PSBL** | DNSBL DNS lookup | No | NO — reputation only |
| **Barracuda** | DNSBL DNS lookup | No | NO — reputation only |
| **SpamCop BL** | DNSBL DNS lookup | No | NO — reputation only |
| **VirusTotal** | API per-IP/domain lookup | `VIRUSTOTAL_KEY` | NO — reputation only |
| **Shodan** | API per-IP lookup | `SHODAN_KEY` | NO — reputation only |
| **Microsoft SNDS** | API bulk data export | `SNDS_KEY` | NO — reputation only |
| **Web form** | Public page at `/report` | No | YES — directly |
| **REST API** | `POST /api/v1/reports` | API key or Bearer token | YES — directly |
| **Manual** | `/admin/cases/create` | No (admin login) | YES — directly |
| **Reputation drop** | Score falls below 30/100 | — | YES — one per IP |

### What Creates Cases vs What Updates Reputation

**Cases are created from human-initiated reports:** email inbox, web form, API, webhooks, manual creation. These represent someone actively reporting an issue.

**Automated scans only update reputation:** AbuseIPDB, DNSBL, VirusTotal, Shodan, SNDS. These are background checks that monitor your IP health. A case is auto-created only when reputation drops critically low (<30/100), ensuring you're not flooded with automated cases.

---

## IP Inventory & Reputation

### How IPs are Checked

Only IPs in your **active inventory** generate cases. This prevents creating cases for IPs you don't manage.

When a report mentions an IP:
1. System scans all IPs in the email/report body
2. Checks each against the `ip_addresses` table
3. First match with `status = active` becomes the case target
4. Customer auto-linked if IP has a `customer_id`

### Reputation Dashboard (`/admin/reputation`)

Shows all IPs with:
- Reputation score bar (0-100, color-coded)
- AbuseIPDB score
- DNSBL listing count
- Microsoft SNDS status (Green/Yellow/Red)
- Open case count
- Per-IP "Scan" button

### Reputation vs Direct Reports

| Source | Creates Case? | When? |
|--------|--------------|-------|
| Email report | YES | Immediately |
| Web form | YES | Immediately |
| API report | YES | Immediately |
| Webhook | YES | Immediately |
| Manual creation | YES | Immediately |
| AbuseIPDB scan | NO | Updates reputation only |
| DNSBL scan | NO | Updates reputation only |
| VirusTotal scan | NO | Updates reputation only |
| Shodan scan | NO | Updates reputation only |
| SNDS scan | NO | Updates reputation only |
| **Reputation < 30** | **YES** | One case per IP, resets at 50 |

---

## Email System

### Inbound (Receiving Reports)

| Method | Configuration |
|--------|--------------|
| POP3 polling | `/admin/brands` → Add connection → Enable auto-import |
| Email API | `POST /api/v1/inbound-email` with raw email body |
| Webhook | `POST /api/v1/webhook/{provider}` (ARF, SpamCop, FBL) |

### Outbound (Sending Replies)

| Feature | How |
|---------|-----|
| ACK on case creation | Automatic via `abuse:poll-mailbox` |
| Reply from case | Click "Reply via Email" on case detail |
| Brand selection | Choose brand in reply form |
| AI drafts | Click "Draft Reporter Reply" → "Use as Reply" |
| Threading | In-Reply-To / References headers auto-set |

### SMTP Fallback (when Mandrill is down)

If Mandrill rejects a message, errors, or has no API key, the email is
automatically re-sent through the **brand's own mailbox over SMTP** — so
outbound mail keeps flowing per-brand during an ESP outage:

- By default the SMTP credentials are **derived from the brand's active
  inbox connection** (same server + login the poller uses; port 465/SSL
  for SSL inboxes, otherwise 587/STARTTLS). Most brands need zero setup.
- Override anything that differs at `/admin/brands` → edit brand →
  *SMTP Fallback* (host, port, username, password, encryption) — e.g.
  when sending goes through `smtp.example.com` instead of `mail.example.com`.
- Fallback sends are recorded in the sent-email log with a
  `via brand SMTP` badge and keep full threading (Message-ID,
  In-Reply-To/References), so replies still land on the right case.

### Loop Prevention

The system prevents email loops by:
1. Detecting our case number `[ABU-XXXX-XXXXX]` in subject or body → follow-up
2. Matching In-Reply-To header against sent emails → follow-up
3. Detecting quoted ACK template text → follow-up
4. Skipping emails from our own addresses
5. Skipping auto-replies, OOO, delivery failures
6. Content analysis: strips ISP wrapper + our template, checks if meaningful content remains

---

## API Reference

### Authentication

Two methods:
```
# API Key (in header)
X-API-Key: your_hashed_api_key

# Bearer Token (Sanctum)
Authorization: Bearer your_sanctum_token
```

### Endpoints

#### Submit Report
```
POST /api/v1/reports
Content-Type: application/json

{
  "abuse_type": "spam",
  "target_ip": "10.0.0.1",
  "target_domain": "example.com",
  "description": "Spam emails from this IP..."
}

Response: 201
{
  "id": "uuid",
  "case_number": "ABU-2026-00001",
  "message": "Report received. Case: ABU-2026-00001"
}
```

#### Submit ARF Report (RFC 5965)
```
POST /api/v1/reports
Content-Type: multipart/report; report-type=feedback-report

[ARF email body]

Response: 201
{
  "id": "uuid",
  "case_number": "ABU-2026-00002",
  "parsed_type": "spam"
}
```

#### Bulk Report
```
POST /api/v1/reports/bulk

{
  "reports": [
    {"abuse_type": "spam", "target_ip": "1.1.1.1", "description": "..."},
    {"abuse_type": "phishing", "target_ip": "2.2.2.2", "description": "..."}
  ]
}

Response: 201
{"count": 2, "ids": ["uuid1", "uuid2"]}
```

#### Check Case Status
```
GET /api/v1/cases/ABU-2026-00001

Response: 200
{
  "case_number": "ABU-2026-00001",
  "status": "investigating",
  "abuse_type": "spam",
  "severity_level": "high"
}
```

#### Webhook / Inbound Endpoints
```
# Generic ARF email pipe (for MTA forwarding)
POST /api/v1/inbound-email
Content-Type: message/rfc822
[raw email body]

# Provider-specific webhooks (if you build custom integrations)
POST /api/v1/webhook/generic_arf     # Any ARF-formatted payload
POST /api/v1/webhook/abuseipdb       # AbuseIPDB push (optional)
POST /api/v1/webhook/spamcop         # SpamCop push (optional)
```

> **Note:** Most abuse data comes via email (POP3) and API polling, not webhooks.
> SpamCop, Microsoft JMRP, and other ISPs send reports as emails to your abuse address.
> Spamhaus, CBL, PSBL, Barracuda are checked via DNSBL DNS lookups.
> The webhook endpoints exist for custom integrations where you want to push data into Abuse AI.

---

## Artisan Commands

### Email & Feed Polling
```bash
# Poll all email connections (auto-import enabled)
php artisan abuse:poll-mailbox

# Poll specific connection
php artisan abuse:poll-mailbox --connection=UUID

# Dry run (show emails without importing)
php artisan abuse:poll-mailbox --dry-run

# Skip ACK replies
php artisan abuse:poll-mailbox --no-ack

# Poll AbuseIPDB feed
php artisan abuse:poll-feeds
```

### IP Reputation Scanning
```bash
# Scan all active IPs (all 6 collectors)
php artisan abuse:scan-reputation --all --create-cases

# Scan single IP (detailed output)
php artisan abuse:scan-reputation --ip=37.221.114.238

# Scan stale IPs (not checked in 6 hours), limit 500
php artisan abuse:scan-reputation --stale=6 --create-cases --limit=500

# Dry run
php artisan abuse:scan-reputation --all --dry-run
```

### Legacy Commands (still available)
```bash
# AbuseIPDB check only
php artisan abuse:check-abuseipdb --all
php artisan abuse:check-abuseipdb --ip=1.2.3.4

# DNSBL check only
php artisan abuse:check-dnsbl --all
php artisan abuse:check-dnsbl --ip=1.2.3.4 --create-cases
```

---

## Scheduler & Automation

### Cron Setup
```bash
# Add to crontab (crontab -e):
* * * * * cd /path/to/abuseai && php artisan schedule:run >> /dev/null 2>&1
```

### Scheduled Tasks
| Interval | Command | What it does |
|----------|---------|-------------|
| Every 2 min | `abuse:poll-mailbox` | Fetch emails → create cases → send ACK |
| Every 15 min | `abuse:poll-feeds` | Poll AbuseIPDB API feed |
| Every 6 hours | `abuse:scan-reputation` | Scan 500 IPs, update reputation, create cases |

### Supervisor (Optional)
```bash
# Copy config
cp deploy/supervisor-scheduler.conf /etc/supervisor/conf.d/abuseai.conf

# Edit paths in the config file, then:
supervisorctl reread
supervisorctl update
supervisorctl status
```

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Laravel 13 (PHP 8.4+) |
| Database | MySQL 8 / PostgreSQL 16 |
| Frontend | Livewire 3 + Alpine.js |
| AI | Claude API / OpenAI API (switchable) |
| Email Sending | Mandrill (Mailchimp Transactional) |
| Email Receiving | POP3 (native socket implementation) |
| Threat Intel | AbuseIPDB, VirusTotal, Shodan, DNSBL, Microsoft SNDS |
| Auth | Laravel Breeze + Sanctum |
| Roles | Spatie Laravel Permission |
| Queue | Redis + Horizon (optional) |
| Tests | Pest PHP (93 tests) |

---

## Admin Pages

| URL | Page |
|-----|------|
| `/admin/cases` | Case queue (filter, sort, bulk actions) |
| `/admin/cases/create` | Create case manually |
| `/admin/cases/{id}` | Case detail + AI panel + reply |
| `/admin/customers` | Customer list |
| `/admin/ips` | IP inventory (add, import, manage) |
| `/admin/reputation` | IP reputation dashboard |
| `/admin/abuseipdb` | AbuseIPDB IP checker |
| `/admin/email-inbox` | Email inbox (POP3, multi-connection) |
| `/admin/reporters` | Reporter list |
| `/admin/analytics` | Dashboard with charts |
| `/admin/audit-log` | Audit log viewer |
| `/admin/users` | User management (create, roles) |
| `/admin/brands` | Brands & email connections |
| `/admin/rules` | Automation rules (CRUD) |
| `/admin/templates` | Email templates (CRUD) |
| `/admin/ai-settings` | AI provider selection |
| `/report` | Public abuse report form |

---

## Contributing

Contributions are welcome. The full guide — dev setup, conventions, PR
process, code-style and test requirements — lives in
[CONTRIBUTING.md](CONTRIBUTING.md). Quick links:

- **Bugs / features**: open an issue using the templates under
  [.github/ISSUE_TEMPLATE](.github/ISSUE_TEMPLATE).
- **Security issues**: do **not** open a public issue — see
  [SECURITY.md](SECURITY.md).
- **Code changes**: branch off `main`, run `composer test` and
  `vendor/bin/pint --test` clean, open a PR using
  [the template](.github/pull_request_template.md).

By contributing, you agree your contributions will be licensed under the
[Apache License 2.0](LICENSE).

---

## License

This project is licensed under the [Apache License 2.0](LICENSE).

You may freely use, modify, and redistribute the source — including for
commercial and managed-service use — subject to the conditions of the license
(retain copyright/license notices, mark modified files, etc.). The license also
includes an explicit grant of patent rights from contributors.

---

## Sponsors

Abuse AI is built and maintained by **[MonoVM](https://monovm.com)** — the
project's main sponsor. MonoVM is a global hosting provider running shared,
VPS, dedicated, and colocation services, and uses Abuse AI in production to
keep its abuse desk under control.

Want to sponsor the project? See [GitHub Sponsors](https://github.com/sponsors/monovm).
