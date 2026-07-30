# Architecture Overview

## System Overview

AbuseAI is a full-stack abuse management platform for datacenter and hosting companies. It receives abuse reports from multiple sources (email, API, web form, feeds, webhooks), triages them with AI, creates and scores cases, automates enforcement actions, and drafts communications -- all through a centralized admin interface.

The system is built on Laravel 13 with PostgreSQL, Redis, and Livewire 3, using AI (Anthropic Claude or OpenAI) at three pipeline stages: triage, intelligence, and communications.

---

## Pipeline Flow

```
[Ingestion]
  Email polling (IMAP) ──┐
  REST API (JSON/ARF) ───┤
  Webhook receivers ──────┤──▶ ReportReceived Event
  Public web form ────────┤
  Feed pollers ───────────┘
        │
        ▼
[AI Pre-Screening]
  Classifier ──▶ Is this actually an abuse report?
  If not_abuse ──▶ flag and skip case creation
        │
        ▼
[Deduplication]
  Fingerprint check via DeduplicationService
  Link duplicates to existing reports
        │
        ▼
[Case Engine]
  CaseCreatorService ──▶ Find or create case (Redis lock)
  SeverityScorerService ──▶ Compute severity 0-100
  CaseScored Event fired
        │
        ▼
[Routing & SLA]
  RoutingService ──▶ Assign queue, SLA deadline, agent
        │
        ▼
[AI Intelligence] (async via queue)
  RescoreCase ──▶ LLM adjusts severity with full context
  AnalyseEvidence ──▶ Summarize headers/URLs/logs
  DetectPatterns ──▶ Cluster related cases
        │
        ▼
[Automation Engine]
  Evaluate AutomationRules against case
  score >= 80 ──▶ SuspendCustomer job
  score >= 50 ──▶ warn customer
  type = CSAM ──▶ immediate EscalateCase
  Send reporter ACK email
        │
        ▼
[AI Communications] (async via queue)
  DraftReporterReply ──▶ ACK / status update email
  DraftCustomerNotice ──▶ Suspension or warning notice
  HandleAppeal ──▶ Read appeal, draft verdict
        │
        ▼
[Admin UI]
  Agent reviews AI drafts, approves/edits, takes actions
[Customer Portal]
  Customer views notices, submits appeals
```

---

## Component Descriptions

### Services

| Service | Path | Purpose |
|---|---|---|
| `ClaudeService` | `Services/AI/ClaudeService.php` | Provider-agnostic AI wrapper with semaphore, retry, and audit logging |
| `AiProviderFactory` | `Services/AI/AiProviderFactory.php` | Factory for switching between Claude and OpenAI providers at runtime |
| `TriageAiService` | `Services/AI/TriageAiService.php` | AI classification, noise filtering, IOC extraction |
| `IntelligenceAiService` | `Services/AI/IntelligenceAiService.php` | AI severity re-scoring, pattern detection, evidence analysis |
| `CommunicationsAiService` | `Services/AI/CommunicationsAiService.php` | AI-drafted emails, appeal handling, case summaries |
| `CaseCreatorService` | `Services/CaseEngine/CaseCreatorService.php` | Creates or links cases with Redis distributed lock |
| `SeverityScorerService` | `Services/CaseEngine/SeverityScorerService.php` | Computes severity score 0-100 using weighted formula |
| `RoutingService` | `Services/CaseEngine/RoutingService.php` | SLA deadline assignment, queue routing, agent assignment |
| `AggregatorService` | `Services/CaseEngine/AggregatorService.php` | Groups and merges related reports into cases |
| `ReportNormalizerService` | `Services/Ingestion/ReportNormalizerService.php` | Normalizes reports from all sources into canonical format |
| `EmailIngestionService` | `Services/Ingestion/EmailIngestionService.php` | IMAP email fetching and parsing |
| `DeduplicationService` | `Services/Ingestion/DeduplicationService.php` | Fingerprint-based duplicate detection |
| `MandrillService` | `Services/Email/MandrillService.php` | Transactional email sending via Mandrill/Mailchimp |
| `InfrastructureLookupService` | `Services/Infrastructure/` | Virtualizor and WHMCS integration for IP/server lookups |
| `GeoIpService` | `Services/Enrichment/GeoIpService.php` | MaxMind GeoIP lookups |
| `ThreatIntelService` | `Services/Enrichment/ThreatIntelService.php` | VirusTotal, Shodan, DNSBL checks |

### Jobs

| Category | Jobs | Queue |
|---|---|---|
| **Ingestion** | `ProcessWebhookReport` | `ingestion` |
| **Processing** | `EnrichReport`, `DeduplicateReport` | `enrichment` |
| **AI Triage** | `TriageReport` | `ai-triage` |
| **AI Intelligence** | `RescoreCase`, `DetectPatterns`, `AnalyseEvidence` | `ai-intel` |
| **AI Communications** | `DraftReporterReply`, `DraftCustomerNotice`, `HandleAppeal` | `ai-comms` |
| **Automation** | `SuspendCustomer`, `BlockIp`, `EscalateCase`, `SendReporterAck`, `SendStatusUpdate` | `automation` / `notifications` |

### Events and Listeners

| Event | Listener | Trigger |
|---|---|---|
| `ReportReceived` | `HandleReportReceived` | New report ingested from any source |
| `CaseScored` | `HandleCaseScored` | Case severity score computed/updated |
| `CaseOpened` | *(logged)* | New case created |
| `CaseActioned` | *(logged)* | Action taken on a case |

The `HandleReportReceived` listener orchestrates the critical path: IP inventory check, AI pre-screening, deduplication, case creation, and dispatching async enrichment/triage jobs.

The `HandleCaseScored` listener triggers the intelligence layer (AI re-scoring, pattern detection, evidence analysis), sends reporter ACKs for new cases, and evaluates automation rules.

### Artisan Commands

| Command | Schedule | Purpose |
|---|---|---|
| `abuse:poll-mailbox` | Every 5 minutes | Poll IMAP connections, AI-classify, create cases, send ACKs |
| `abuse:poll-feeds` | Every 15 minutes | Poll AbuseIPDB and other feed sources |
| `abuse:check-abuseipdb` | Periodic | Check IP inventory against AbuseIPDB |
| `abuse:check-dnsbl` | Every 12 hours | Check IPs against DNS blacklists |
| `abuse:scan-reputation` | Periodic | Aggregate reputation scores across all sources |
| `abusedesk:check-config` | Manual | Validate system configuration and connectivity |

---

## Database Schema

All tables use UUID primary keys.

### Core Tables

| Table | Purpose | Key Relationships |
|---|---|---|
| `reporters` | External entities that submit reports | Has many `abuse_reports` |
| `customers` | Hosting customers who are subjects of reports | Has many `abuse_cases`, `suspension_history` |
| `abuse_cases` | Central case record grouping related reports | Belongs to `customer`, `brand`, `assignee`; has many `abuse_reports`, `case_actions` |
| `abuse_reports` | Individual abuse reports from any source | Belongs to `abuse_case`, `reporter` |
| `case_actions` | Timeline of actions taken on a case | Belongs to `abuse_case`, actor `user` |
| `audit_logs` | Full audit trail including AI calls | Polymorphic on `entity_type`/`entity_id` |
| `suspension_history` | Customer suspension/unsuspension records | Belongs to `customer`, `abuse_case` |
| `email_templates` | Configurable email templates per abuse type | Standalone |
| `automation_rules` | Trigger-condition-action rules engine | Standalone |

### Supporting Tables

| Table | Purpose |
|---|---|
| `brands` | Multi-brand support with per-brand SMTP, WHMCS, Virtualizor config |
| `email_connections` | IMAP/SMTP connection settings per brand |
| `ip_addresses` | IP inventory with AbuseIPDB scores and reputation data |
| `sent_emails` | Log of outbound emails with Mandrill message IDs |
| `notification_preferences` | Per-user notification settings |

### Case Number Format

Cases are numbered `ABU-{YEAR}-{SEQUENCE}`, e.g., `ABU-2026-00042`.

---

## AI Integration

AI is used at three pipeline layers, with a provider-agnostic architecture supporting both Anthropic Claude and OpenAI.

### Provider Architecture

```
ClaudeService (facade)
  └── AiProviderInterface
        ├── ClaudeProvider (Anthropic API)
        └── OpenAiProvider (OpenAI API)
```

The active provider and model can be switched at runtime via the admin UI. Settings are cached and override `.env` defaults.

### Concurrency Control

- Redis semaphore limits concurrent AI calls (default: 10)
- 3 retry attempts with exponential backoff on overload responses
- Graceful degradation: AI failures never block the case pipeline
- Every AI call (prompt + response) is logged to `audit_logs`

### Layer 1: Triage

- **Classifier**: Determines abuse type and extracts IOCs (IPs, domains, URLs). Also filters non-abuse content (ticket notifications, auto-replies, bounce messages).
- **Noise Filter**: Scores report quality 0-1 (1.0 = noise/not-abuse, 0.0 = actionable).
- **Parser**: Extracts structured data from raw report text.

### Layer 2: Intelligence

- **Severity Re-scorer**: LLM reads full case context and adjusts the computed severity score.
- **Pattern Detector**: Clusters related cases by pattern type (botnet, coordinated spam, repeat offender, etc.).
- **Evidence Analyser**: Summarizes headers, URLs, and logs for agent review.
- **Customer Profiler**: Generates risk profile from billing and abuse history.

### Layer 3: Communications

- **Reporter Reply**: Drafts ACK, status update, and resolution emails.
- **Customer Notice**: Drafts suspension or warning notices with evidence.
- **Appeal Handler**: Assesses appeal validity, recommends verdict (uphold/overturn/investigate).
- **Case Summariser**: Generates handoff notes for agent review.

---

## Severity Scoring

Implemented in `SeverityScorerService::calculate()`:

```
score = base_weight[type]
      x log10(report_count + 1) x 10
      x (avg_reporter_reputation / 100)
      x recurrence_factor
```

Where:
- `base_weight` is per abuse type (spam=2, phishing=4, malware=5, ddos=6, botnet=7, law_enforcement=8, csam=10)
- `recurrence_factor` = 1.0 + (prior_cases_on_customer x 0.25)
- Score is capped at 100

**CSAM always returns 100 and triggers immediate escalation.**

| Score Range | Severity Level | Action |
|---|---|---|
| 0-20 | Low | No automatic action |
| 21-49 | Medium | Flag for review |
| 50-79 | High | Warn customer, restrict service |
| 80-100 | Critical | Auto-suspend |

---

## Queue Architecture

Queues are managed by Laravel Horizon with Redis as the driver.

| Queue | Workers | Timeout | Purpose |
|---|---|---|---|
| `ingestion` | 4 | 60s | Feed polling, email parsing, webhook processing |
| `ai-triage` | 4 | 120s | AI classification and noise filtering |
| `enrichment` | 8 | 30s | GeoIP, WHOIS, threat intel lookups |
| `case-engine` | 4 | 60s | Case creation, scoring, routing |
| `ai-intel` | 2 | 180s | AI intelligence (re-scoring, patterns, evidence) |
| `automation` | 4 | 120s | Suspend, block, escalate |
| `ai-comms` | 2 | 120s | AI-drafted emails and appeal handling |
| `notifications` | 4 | 30s | Outbound emails, Slack, webhooks |
| `default` | 2 | 60s | Everything else |

---

## Event-Driven Design

The system follows an event-driven architecture where state changes produce events that trigger downstream processing:

1. **Report ingested** -> `ReportReceived` event -> `HandleReportReceived` listener runs AI pre-screening, dedup, case creation synchronously, then dispatches async enrichment and triage jobs.

2. **Case scored** -> `CaseScored` event -> `HandleCaseScored` listener triggers routing, AI intelligence jobs, reporter ACK, and automation rule evaluation.

3. **Automation rules** are evaluated on every score change. Rules match on abuse type, severity score, and custom field conditions. Matching rules dispatch enforcement jobs (suspend, block, escalate, notify).

4. **Audit logging** captures every state change, every AI call, and every action via the `audit_logs` table and model observers.

This design decouples ingestion from processing, allowing the system to handle bursts of reports without blocking, and enabling AI operations to fail gracefully without losing reports.

---

## Security Model

### Authentication

- **Admin UI**: Laravel Breeze session authentication
- **Reporter API**: `X-API-Key` header (bcrypt-hashed in DB) or Sanctum Bearer tokens
- **Customer Portal**: Session authentication
- **Webhooks**: HMAC signature verification per provider (SHA-256, with SHA-1 fallback)

### Rate Limiting

- API: 60 requests/minute per API key or IP (Redis sliding window via `ReporterRateLimit` middleware)
- AI: Maximum 10 concurrent AI calls via Redis semaphore
- Email polling: Maximum 20 AI classification calls per poll run

### Data Protection

- Brand SMTP/WHMCS/Virtualizor credentials stored with Laravel's `encrypted:array` cast
- API keys stored as bcrypt hashes, never in plaintext
- Evidence files stored in local/S3 storage, not in the database
- Raw emails stored with unique paths under `emails/{year}/{month}/`

### CSAM Handling

- CSAM reports are always scored as critical (100)
- Immediate escalation, bypass normal scoring pipeline
- Configurable law enforcement email and NCMEC API key

### Audit Trail

- Every state change on cases, reports, and customers is logged
- Every AI call records the full prompt and response
- Actions record the actor (user ID or null for automated)
- IP addresses recorded on audit entries where available
