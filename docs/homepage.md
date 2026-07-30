# Abuse AI

> **The AI-powered abuse desk for hosting companies and ISPs.**
> Receive, triage, score, and action abuse reports — automatically — with
> Claude / OpenAI at every key step.

Built for the team that's tired of staring at a `abuse@` mailbox: 800
ARF reports a week, half of them duplicates, the other half misclassified,
and one of them is a real DDoS your customer is launching right now.

---

## What it does, in one screen

```
┌─ INGEST ────────────────────────────────────────────────────────────────┐
│  IMAP / POP3   ·   REST API   ·   Webhooks   ·   Public form   ·   ARF │
└───────────────────────────────┬─────────────────────────────────────────┘
                                │
        ┌───────────────────────▼───────────────────────┐
        │  AI TRIAGE                                    │
        │  classify · noise filter · IOC extract · i18n │
        └───────────────────────┬───────────────────────┘
                                │
        ┌───────────────────────▼───────────────────────┐
        │  IP INVENTORY MATCH                           │
        │  Is this our IP? Whose? Active or decom?      │
        └───────────────────────┬───────────────────────┘
                                │
        ┌───────────────────────▼───────────────────────┐
        │  THREAT INTEL ENRICHMENT                      │
        │  AbuseIPDB · VirusTotal · Shodan · DNSBL ·    │
        │  Microsoft SNDS · GeoIP · WHOIS · ASN         │
        └───────────────────────┬───────────────────────┘
                                │
        ┌───────────────────────▼───────────────────────┐
        │  CASE ENGINE                                  │
        │  dedup · score · route · SLA · aggregate      │
        └───────────────────────┬───────────────────────┘
                                │
        ┌───────────────────────▼───────────────────────┐
        │  AI INTELLIGENCE                              │
        │  re-score · pattern detect · evidence summary │
        └───────────────────────┬───────────────────────┘
                                │
        ┌───────────────────────▼───────────────────────┐
        │  AUTOMATION                                   │
        │  WHMCS suspend · open ticket · null-route ·   │
        │  notify reporter · escalate · CSAM → NCMEC    │
        └───────────────────────┬───────────────────────┘
                                │
        ┌───────────────────────▼───────────────────────┐
        │  AI COMMUNICATIONS                            │
        │  draft reporter ack · customer notice ·       │
        │  appeal verdict · case summary handoff        │
        └───────────────────────────────────────────────┘
```

A spam report that would have taken your agent 8 minutes to read,
classify, look up, suspend, ticket, and reply to is **fully actioned
in under 30 seconds** — without an agent ever touching it.

---

## Features

### 🧠 AI at every step (Claude or OpenAI)

| Layer | What the AI does |
|---|---|
| **Triage** | Classifies report type, extracts IOCs (IPs, domains, URLs, timestamps), filters automated noise, translates non-English reports |
| **Intelligence** | Re-scores severity using full case context, detects botnet / coordinated-spam patterns across cases, summarises evidence, profiles customer abuse history |
| **Communications** | Drafts reporter ACK + status emails, customer abuse notices, appeal verdicts, case summaries for agent handoff — all reviewed before send |

Every prompt and response is logged to an encrypted audit log. AI never
auto-applies critical actions without human approval flags.

Provider-agnostic: switch between Anthropic Claude and OpenAI with one env
var. Cost + token usage tracked per call, dashboarded under
`/admin/ai-usage`.

### 📥 Ingest from anywhere

- **Email**: per-brand IMAP / POP3 inboxes, polled every 2 minutes. Parses
  RFC 5965 ARF feedback loops, plain-text reports, forwarded notices, and
  CERT advisories. Handles multipart MIME, base64 / quoted-printable,
  attachments (`.eml`, `.pdf`, `.png`, `.txt`).
- **REST API**: signed endpoints for partners and feed aggregators.
  Bulk-submit up to 100 reports per request.
- **Public web form**: branded per-host (one form serves every brand on
  the same app via the Host header), reCAPTCHA v3 protected.
- **Webhooks**: signed receivers for AbuseIPDB, Spamhaus, Google FBL,
  Microsoft FBL, SpamCop, and a generic ARF endpoint. HMAC verification
  is mandatory — no silent fail-open.
- **MTA pipe**: forward `abuse@yourdomain.com` directly into a shared-
  secret-protected endpoint for instant ingestion.

### 🌐 IP inventory & reputation

Continuous health monitoring of every IP in your network:

- **6 data sources**: AbuseIPDB, DNSBL (Spamhaus, SBL, XBL, CSS, …),
  open cases, VirusTotal, Shodan vulnerabilities, Microsoft SNDS.
- **Aggregate reputation score 0–100**, automatically opens cases below
  the configured threshold and resets the flag on recovery.
- **CIDR-aware import**: paste a `/28`, a dash range, or a CSV — the
  system expands and tags appropriately.
- **History tracking**: per-IP reputation timeline, prior-owner audit,
  decommissioned-range support so old IPs can't be re-claimed silently.

### 🏷️ Multi-brand by design

Run multiple hosting brands or resellers from a single deployment:

- Each brand has its own logo, colors, public report form (served on its
  own hostname), email identity (Mandrill key, signature, footer),
  and infrastructure provider config.
- Brand-aware case routing: a report for `abuse@brand-a.example`
  resolves to Brand A's WHMCS regardless of which IP it points at.
- Per-brand `/report`, `/partner`, `/api` pages, all branded against
  the requesting host.

### 🔌 Infrastructure provider support

The lookup layer is provider-modular. Add credentials, get the workflow.

| Provider | Lookup | Suspend | Unsuspend | Open Ticket |
|---|:-:|:-:|:-:|:-:|
| WHMCS | ✅ | ✅ | ✅ | ✅ |
| Virtualizor | ✅ | ✅ | ✅ | — |
| WHMCS + Virtualizor | ✅ | ✅ | ✅ | ✅ |
| SolusVM v1 | ✅ | ✅ | ✅ | — |
| Proxmox VE | ✅ | ✅ | ✅ | — |
| Blesta | ✅ | ✅ | ✅ | ✅ |
| HostBill | ✅ | ✅ | ✅ | ✅ |
| Generic HTTP | configurable | configurable | configurable | — |

Each brand picks its provider mode in the admin UI. The case detail page
shows only the buttons the active provider supports — no dead-end clicks.

### ⚙️ Automation engine

Rule-based action triggers with a friendly UI editor. Default rules ship
with the seeder:

- **Auto-suspend on critical score** (`severity_score >= 60`) — opens a
  WHMCS ticket and suspends the service in one go.
- **Trusted-reporter + WHMCS service auto-suspend** — when a known-good
  reporter (Spamhaus, Google FBL) hits an IP that resolves to a real
  service, suspend regardless of score.
- **Block IP on DDoS with high score** — null-route via firewall API.
- **CSAM immediate escalation** — bypass scoring entirely, suspend +
  notify law enforcement, set legal hold.
- **Law enforcement escalate + notify** — Slack ping, legal hold flag.
- **SLA breach escalate** — when `sla_deadline` is past, page the
  on-call.
- **Reporter ACK on new case** — friendly email confirming receipt.

Rules support: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in` operators across any
case field plus dot-path metadata (`metadata.from_law_enforcement`,
`metadata.whmcs_service_id`). Priorities, abuse-type filters, minimum-
score gates.

### 🛡️ Smart deduplication

Three-layer dedup:

1. **Exact** — same reporter + target + type → marks duplicate
2. **Target** — same target + type, any reporter → links to existing
   case (counts toward `report_count` for scoring)
3. **Content hash** — normalized text fingerprint catches copy-paste /
   bot reports

Plus a Redis sliding window (24h default) and a DB fallback if Redis is
unavailable.

### 📧 Email loop prevention

Surprisingly important: when the abuse desk emails the reporter, the
reporter's mail provider auto-forwards a copy back to the abuse desk,
which would create a second case about itself.

We block that loop with:

- Skip emails from our own configured `from_addresses`
- Detect replies to our ACK emails → attach as follow-up, no new case
- Detect ISP auto-forward quoting our content → link to existing case
- Skip auto-replies, OOO, delivery-failure, mailer-daemon
- RFC 3834 `Auto-Submitted` header detection
- Content dedup (strips our own template, checks for meaningful
  remaining content)

### 🌍 Multi-language

Triage prompts handle non-English reports natively. Reports in Russian,
Mandarin, Spanish, German, French, Persian, Arabic — they all classify
correctly. The AI extracts IOCs from the original text and writes the
case summary in English so your agents can act on it without
translation tooling.

### 🔍 Audit & compliance

- **Every state change** logged: old / new values, actor, IP, timestamp.
- **Every AI call** logged with prompt + response (encrypted at rest)
  + provider + model + token usage + cost.
- **Per-customer** suspension history with reason, who actioned, what
  the WHMCS API returned.
- **Legal hold** flag on cases, surfacing in the UI and protecting
  evidence from automated cleanup.

### 🎯 Reporter reputation

Reporters earn or lose trust based on the quality of their reports:

- Trusted-feed sources start at 90+ (AbuseIPDB, Spamhaus, Google FBL,
  Microsoft FBL).
- Public form submitters start in the middle, move based on case
  outcome.
- Bot / spam reporters get auto-blocked when reputation drops below
  threshold.
- Law-enforcement domains (FBI, NCMEC, Interpol, Europol, FR/UK/CA
  national police) auto-tag at the first email and bypass the noise
  filter — an LE inquiry always opens a case.

### 📊 Analytics dashboard

- Real-time queue health: open cases by severity, SLA status, queue depth
- Volume by abuse type (spam, phishing, malware, DDoS, …) over time
- Reporter mix (feed vs. form vs. webhook vs. email)
- AI cost + tokens per provider / model / day
- Customer abuse heatmap by brand

### 🔐 Security

- All admin routes behind `auth + role:admin` middleware
- Reporter API keys hashed (`bcrypt`), not stored plaintext
- Per-API-key + per-IP rate limiting (Redis sliding window)
- Webhook payloads HMAC-verified — empty / missing secret = reject
- Brand integration credentials encrypted at rest
  (smtp / WHMCS / Virtualizor / SolusVM / Proxmox / Blesta / HostBill)
- AI prompts and responses encrypted at rest in the audit log
- Session encryption + secure cookies (production defaults)
- Public abuse form requires reCAPTCHA v3 + per-IP throttle
- Defense-in-depth root `.htaccess` blocks `.env`, `composer.*`,
  source directories, dotfiles even in shared-hosting layouts
- Session invalidation on password reset
- See [SECURITY.md](https://github.com/monovm/abuseai/blob/main/SECURITY.md)
  for the private vulnerability disclosure path.

---

## How agents use it

A typical case detail page exposes everything the agent needs without
context-switching:

- **Timeline**: every action, every linked report, every email sent
- **Evidence aggregator**: attachments from every linked report in one
  list, with one-click download
- **Infrastructure card**: live WHMCS / Virtualizor lookup with service
  ID, hostname, customer, registration date — and one-click actions:
  - **Suspend Service**
  - **Unsuspend Service**
  - **Notify Client (Ticket Only)** — for medium-severity reports where
    a warning is enough
  - **Suspend + Open Ticket** — manual equivalent of the auto-suspend
    rule
- **Reply via Email**: AI-drafted reporter reply, agent edits + sends
  through Mandrill
- **AI Assistant panel**: re-score, regenerate summary, draft customer
  notice, draft reporter reply
- **Smart bind warning**: when the current service was registered after
  the abuse occurred, the UI flags it amber (or red if abuse fully
  predates the service) so the agent knows whether the *current* owner
  is on the hook — abuse that *spans* a registration date links to the
  new owner for the post-registration portion.

---

## What's in the box

```
abusedesk/
├── app/
│   ├── Console/Commands/    Feed pollers, mailbox poller, reputation scanners
│   ├── Events/              ReportReceived · CaseOpened · CaseScored
│   ├── Listeners/           HandleReportReceived · HandleCaseScored
│   ├── Http/Controllers/
│   │   ├── Api/             Reporter API + webhook receivers
│   │   ├── Admin/           Case management UI controllers
│   │   ├── Portal/          Customer portal (cases + appeal)
│   │   └── WebForm/         Public abuse form + API docs + partner signup
│   ├── Jobs/
│   │   ├── Ingestion/       Feed poll, email parse, webhook process
│   │   ├── Processing/      Enrichment, dedup
│   │   ├── AI/              Triage, rescore, draft, pattern-detect, appeal
│   │   └── Automation/      Suspend, block-IP, escalate, notify,
│   │                        link-customer-from-infrastructure
│   ├── Livewire/Admin/      Case queue, case detail, customer profile,
│   │                        IP manager, brand manager, rule editor,
│   │                        template editor, AI panel, analytics, audit log,
│   │                        reporter manager, AI usage, email inbox
│   ├── Services/
│   │   ├── AI/              Claude + OpenAI provider wrappers, triage,
│   │   │                    intelligence, communications, translation
│   │   ├── CaseEngine/      CaseCreator, Aggregator, SeverityScorer, Router
│   │   ├── Enrichment/      AbuseIPDB, VirusTotal, Shodan, DNSBL, SNDS,
│   │   │                    threat intel aggregator
│   │   ├── Infrastructure/  WHMCS, Virtualizor, SolusVM, Proxmox, Blesta,
│   │   │                    HostBill, Generic-HTTP — one provider interface
│   │   ├── Ingestion/       Feed pollers, dedup, normalizer, email parser,
│   │   │                    ARF parser
│   │   └── Email/           Mandrill API client
│   └── Observers/           Audit log writers
├── config/
│   ├── abusedesk.php        Scoring, SLA, dedup, feeds, webhooks, WHMCS,
│   │                        Virtualizor, CSAM, law-enforcement domains
│   └── ai.php               AI provider config + prompt templates
├── database/
│   ├── migrations/          UUID PKs, jsonb metadata, encrypted columns
│   └── seeders/             Roles · default automation rules · email templates
├── deploy/
│   ├── nginx.conf.example   Production-shape vhost (docroot at public/,
│   │                        security headers, defense-in-depth deny rules)
│   ├── supervisor-*.conf    Horizon, scheduler, worker process definitions
│   └── crontab              Alternative scheduler entry
└── docs/
    ├── architecture.md      System architecture deep-dive
    ├── api.md               REST API reference
    ├── admin-guide.md       Operator playbook
    └── deployment.md        Production deployment guide
```

---

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.4) |
| UI | Livewire 3 + Alpine.js + Tailwind CSS 3 |
| Database | PostgreSQL 16 (recommended) or MySQL 8 |
| Cache / Queue | Redis 7 via Laravel Horizon |
| Search / Analytics | Elasticsearch 8 (optional, for case search) |
| Object storage | S3-compatible (AWS S3, MinIO, etc.) for evidence files |
| AI | Anthropic Claude or OpenAI GPT — pluggable |
| Mail | Mandrill (Mailchimp Transactional) — pluggable |
| Auth | Laravel Breeze (admin) + Sanctum (API) + Spatie Permission (RBAC) |
| Workers | Laravel Horizon (8 dedicated queues) |
| Testing | Pest PHP |

---

## Roles & permissions

Built-in roles (extensible via the admin UI):

- **Admin** — full access, including settings, brand config, automation
  rules, user management
- **Agent** — case management (view, edit, action), AI triggering, no
  settings access
- **Viewer** — read-only across cases, reports, customers, IPs,
  analytics

Destructive actions on case-detail / customer / IP / email pages are
admin-gated even when the parent route allows agents — defense-in-depth
against role-downgrade attacks via direct Livewire AJAX calls.

---

## Real workloads it handles

- 800+ ARF reports per day from a single Spamhaus FBL feed
- 50+ public form submissions during an active DDoS targeting a
  customer
- Mail-server brands receiving Microsoft / Google FBL bursts (1k+ in an
  hour)
- Multi-tenant deployments with 5+ brands, 50k+ IPs, 10+ providers

The case engine uses Redis distributed locks per `(target_ip, abuse_type)`
to prevent race-condition duplicate cases when reports for the same IP
arrive within milliseconds of each other.

---

## Self-hosted, open source

Abuse AI ships under the **Apache License 2.0**:

- ✅ Free to use, modify, redistribute (commercial or not)
- ✅ Run as many brands as you want, on as many hosts as you want
- ✅ Offer it as a hosted/managed service to third parties
- ✅ Fork, extend, integrate with anything
- ✅ Explicit patent grant from contributors

Just retain the copyright/license notices and mark any modified files.

---

## Get started

```bash
git clone https://github.com/monovm/abuseai.git
cd abuseai

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate

# SQLite for first boot, switch to pgsql/mysql later
touch database/database.sqlite
php artisan migrate --seed

# Spin up the dev stack (server + queue + log tail + Vite)
composer dev
```

Then visit `http://localhost:8000` and log in with the seeded admin
account.

Production deployment guide: [docs/deployment.md](https://github.com/monovm/abuseai/blob/main/docs/deployment.md)

Operator's playbook: [docs/admin-guide.md](https://github.com/monovm/abuseai/blob/main/docs/admin-guide.md)

---

## Built for

- Hosting companies running shared, VPS, dedicated, or colo
- ISPs and transit providers managing customer IP space
- Datacenter operators who need a real abuse desk, not a forwarded
  mailbox
- Resellers running multiple brands on the same infrastructure
- Anyone whose `abuse@` mailbox has crossed 50 messages a day

---

## Learn more

- 📦 **GitHub**: <https://github.com/monovm/abuseai>
- 📖 **Architecture deep-dive**: [docs/architecture.md](https://github.com/monovm/abuseai/blob/main/docs/architecture.md)
- 🔐 **Security policy**: [SECURITY.md](https://github.com/monovm/abuseai/blob/main/SECURITY.md)
- 🤝 **Contributing**: [CONTRIBUTING.md](https://github.com/monovm/abuseai/blob/main/CONTRIBUTING.md)
- 🛡 **License (Apache 2.0)**: [LICENSE](https://github.com/monovm/abuseai/blob/main/LICENSE)

---

*Stop drowning in `abuse@`. Start closing cases.*
