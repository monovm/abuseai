# Admin Guide

## Dashboard Overview

The admin dashboard (`/admin/dashboard`) provides a high-level view of abuse desk activity:

- **Open cases** count by severity level (critical, high, medium, low)
- **Reports today** versus historical average
- **SLA compliance** -- cases approaching or past their deadlines
- **Recent activity** timeline showing latest case actions

The sidebar navigation provides access to all admin sections: Cases, Reports, Customers, Email Inbox, IP Inventory, Reputation, AbuseIPDB, Analytics, Reporters, Brands, Rules, Templates, AI Settings, Audit Log, and User Management.

---

## Managing Cases

### Case Queue (`/admin/cases`)

The case queue is the primary work surface. It displays all abuse cases in a filterable, sortable table.

**Filtering options:**
- Status: open, investigating, actioned, resolved, closed
- Severity level: low, medium, high, critical
- Abuse type: spam, phishing, malware, DDoS, CSAM, copyright, fraud, law enforcement, brute force, intrusion, botnet, other
- Assignee
- Brand
- Date range
- Target IP or domain (search)

**Bulk actions:** Select multiple cases to perform bulk status changes, assignments, or exports.

**Case numbers** follow the format `ABU-{YEAR}-{SEQUENCE}` (e.g., ABU-2026-00042).

### Case Detail (`/admin/cases/{id}`)

The case detail view shows:

- **Header**: Case number, status badge, severity score, abuse type, target IP/domain, assigned agent, SLA deadline
- **Timeline**: Chronological log of all actions (status changes, notes, emails sent, AI operations, assignments)
- **Reports tab**: All linked abuse reports with source, reporter, AI classification, and raw payload
- **Evidence tab**: Extracted IOCs, email headers, attachment files
- **AI Panel**: AI-generated analysis, severity re-score reasoning, pattern flags, drafted emails for review
- **Customer info**: Linked customer record, abuse score, service details

**Available actions on a case:**
- Change status (open -> investigating -> actioned -> resolved -> closed)
- Assign to agent
- Add note
- Send email to reporter (from AI draft or manual)
- Send notice to customer
- Suspend/unsuspend customer
- Block/unblock IP
- Escalate
- Place on legal hold
- Merge with another case
- AI: Request re-score, generate summary, draft reply

### Creating a Case Manually

Navigate to `/admin/cases/create` to manually create a case. Provide the abuse type, target IP or domain, description, and optionally link to a customer.

---

## Managing Reports

### Report Queue (`/admin/reports`)

The reports view shows summary statistics:

- **Total reports** in the system
- **Today's reports** count
- **Unlinked reports** (reports not yet associated with a case)
- **Noise reports** (flagged by AI as non-abuse or high noise score > 0.8)
- **Duplicate reports** count

The report queue Livewire component (`ReportQueue`) allows filtering by source, abuse type, linked/unlinked status, and AI noise score.

**AI triage results** are displayed per report:
- Classification type and confidence score
- Noise score (0 = actionable, 1 = noise)
- Extracted IOCs (IPs, domains, URLs)
- Whether the report was flagged as "not abuse"

---

## Email Inbox (`/admin/email-inbox`)

The email inbox (`EmailInbox` Livewire component) provides a view of emails received on configured IMAP connections.

Emails are polled automatically by the `abuse:poll-mailbox` command (scheduled every 5 minutes). The inbox shows:

- Sender, subject, date
- AI classification result (abuse type or "not abuse")
- Whether a case was created or the email was linked to an existing case
- Follow-up detection (replies referencing existing case numbers)

**Automatic filtering applied during polling:**
- Emails from our own addresses are skipped (loop prevention)
- Auto-replies, out-of-office, and delivery failure notifications are skipped
- Emails with `Auto-Submitted` header (RFC 3834) are skipped
- Duplicate emails (by Message-ID) are skipped
- Emails that only quote our own ACK template are skipped
- Customer support inquiries (non-abuse) are detected and skipped

---

## Automation Rules (`/admin/rules`)

The rule editor (`RuleEditor` Livewire component) lets you define trigger-condition-action rules that fire automatically when cases are scored.

### Creating a Rule

Each rule consists of:

1. **Name**: Descriptive label
2. **Trigger event**: When the rule is evaluated
   - `score_changed` -- after severity scoring
   - `report_received` -- when a new report arrives
   - `status_changed` -- when case status changes
   - `time_elapsed` -- SLA timeout
   - `appeal_received` -- customer appeal submitted
3. **Conditions**: Field-operator-value checks against the case
   - Field: any case attribute (e.g., `severity_score`, `abuse_type`, `report_count`)
   - Operator: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`
   - Value: comparison target
4. **Abuse types**: Limit to specific types or apply to all
5. **Minimum score**: Only fire if severity score meets threshold
6. **Actions**: What to do when conditions match
   - `suspend` -- dispatch SuspendCustomer job
   - `block_ip` -- dispatch BlockIp job
   - `escalate` -- dispatch EscalateCase job
   - `notify` -- dispatch SendStatusUpdate job
7. **Priority**: Lower number = higher priority (evaluated in order)

Rules can be activated/deactivated. The trigger count and last triggered timestamp are tracked.

---

## Email Templates (`/admin/templates`)

The template editor (`TemplateEditor` Livewire component) manages reusable email templates.

**Template types:**
- `reporter_ack` -- acknowledgement sent to reporters
- `reporter_status` -- status update for reporters
- `reporter_resolved` -- resolution notification for reporters
- `customer_warning` -- abuse warning to customers
- `customer_suspension` -- suspension notice to customers
- `customer_appeal_result` -- appeal decision notification

Templates can be scoped to specific abuse types or apply to all types. Each template has:
- HTML body and plain text body
- Subject line
- Available merge variables (documented per template)
- Active/inactive toggle

---

## Brand Management (`/admin/brands`)

Brands (`BrandManager` Livewire component) support multi-tenant operation with separate identities for different hosting brands.

Each brand configures:
- **Identity**: Name, from email, reply-to email, logo URL, website URL
- **Email signature and footer**: Appended to outbound emails
- **SMTP config**: Per-brand Mandrill or SMTP settings (encrypted at rest)
- **WHMCS config**: Per-brand billing API credentials (encrypted at rest)
- **Virtualizor config**: Per-brand panel API credentials (encrypted at rest)
- **Hostname pattern**: Comma-separated patterns to match VPS hostnames for auto-brand detection
- **Email connections**: IMAP connections linked to this brand for inbound email polling

One brand is marked as the default. When a report arrives for an IP, the system determines the brand by:
1. Checking if an existing case for that IP already has a brand
2. Looking up the IP in Virtualizor, matching the VPS hostname against brand patterns
3. Falling back to the default brand

---

## IP Inventory (`/admin/ips`)

The IP inventory (`IpManager` Livewire component) tracks all IP addresses assigned to your infrastructure.

Each IP record includes:
- IP address, server name, status (active/inactive), tags, notes
- Linked customer
- **AbuseIPDB score**: Confidence of abuse score from AbuseIPDB (0-100)
- **AbuseIPDB report count**: Number of reports on AbuseIPDB
- **Reputation score**: Composite score aggregated from multiple sources (0-100, higher = better)
- **Reputation details**: Breakdown by source (AbuseIPDB, DNSBL, VirusTotal, Shodan, SNDS)

**Why IP inventory matters:** When a report arrives, the system checks if the target IP is in the inventory (`IpAddress::isOurs()`). Only IPs in the inventory generate cases. This prevents false positives from IPs mentioned in report headers that belong to third parties.

IPs can be imported individually or in bulk. The `abuse:check-abuseipdb` and `abuse:scan-reputation` commands update scores periodically.

---

## AI Configuration (`/admin/ai-settings`)

The AI settings page (`AiSettings` Livewire component) controls the AI provider configuration.

### Provider Selection

Switch between providers at runtime:
- **Anthropic Claude**: Models include Claude Sonnet 4, Claude Haiku 4.5, Claude Opus 4.6
- **OpenAI**: Models include GPT-4o, GPT-4o Mini, GPT-4.1, GPT-4.1 Mini, GPT-4.1 Nano, GPT-4 Turbo, o1, o1 Mini, o3, o3 Mini, o4 Mini

The active provider and model are cached and take effect immediately. The provider is set via `AiProviderFactory::setActiveProvider()` and the model via cache key.

### Configuration

- **Max concurrent calls**: Redis semaphore limit (default 10)
- **Log calls**: Whether to log all prompts and responses to audit trail
- **Retry attempts**: Number of retries on API errors (default 3)

API keys are configured in `.env`:
- `ANTHROPIC_API_KEY` for Claude
- `OPENAI_API_KEY` for OpenAI

---

## CSV Export

Export case and report data as CSV files:

- **Export cases**: `GET /admin/export/cases` -- downloads all cases with key fields
- **Export reports**: `GET /admin/export/reports` -- downloads all reports

---

## Case Merge

From the case detail view, cases can be merged when duplicate cases exist for the same target. Merging moves all reports from the source case to the target case, updates report counts, and closes the source case with a note.

---

## Reputation Dashboard (`/admin/reputation`)

The reputation dashboard (`ReputationDashboard` Livewire component) shows an aggregate view of IP reputation across all sources:

- Distribution of IPs by reputation label (Good, Fair, Poor, Critical)
- IPs with low reputation scores that may need proactive attention
- Trend data from periodic reputation scans

Reputation is calculated from multiple weighted sources:
- AbuseIPDB score (weight: 0.5)
- DNSBL listings (15 points per list, max 40)
- Open abuse cases (10 points per case, max 20)
- VirusTotal malicious ratio (weight: 0.3)
- Shodan vulnerabilities (5 points per vuln)
- Microsoft SNDS data (weight: 1.0)
