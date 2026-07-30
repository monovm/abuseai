@extends('layouts.admin')
@section('header', 'Admin User Guide')
@section('title', 'Admin User Guide - ' . config('app.name', 'Abuse AI'))

@section('content')
    <style>
        .guide-wrap { display: grid; grid-template-columns: 240px 1fr; gap: 20px; align-items: start; }
        .guide-toc { position: sticky; top: 72px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; max-height: calc(100vh - 90px); overflow-y: auto; }
        .guide-toc h4 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; margin: 0 0 8px; }
        .guide-toc a { display: block; font-size: 13px; color: #374151; text-decoration: none; padding: 5px 8px; border-radius: 4px; line-height: 1.3; }
        .guide-toc a:hover { background: #f3f4f6; color: #2563eb; }
        .guide-toc a.sub { padding-left: 20px; font-size: 12px; color: #6b7280; }
        .guide-content { min-width: 0; }
        .guide-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px 24px; margin-bottom: 16px; scroll-margin-top: 70px; }
        .guide-section h2 { font-size: 20px; font-weight: 700; margin: 0 0 4px; color: #111827; }
        .guide-section h3 { font-size: 15px; font-weight: 600; margin: 18px 0 8px; color: #1f2937; }
        .guide-section h4 { font-size: 13px; font-weight: 600; margin: 14px 0 6px; color: #374151; text-transform: uppercase; letter-spacing: 0.04em; }
        .guide-section p, .guide-section li { font-size: 13.5px; line-height: 1.6; color: #374151; }
        .guide-section ul, .guide-section ol { padding-left: 20px; margin: 8px 0; }
        .guide-section li { margin-bottom: 4px; }
        .guide-section code { background: #f3f4f6; padding: 1px 6px; border-radius: 4px; font-size: 12.5px; color: #be185d; font-family: 'SF Mono', Menlo, monospace; }
        .guide-section .lede { font-size: 14px; color: #6b7280; margin-bottom: 12px; }
        .guide-section a.route-link { color: #2563eb; text-decoration: none; font-weight: 500; }
        .guide-section a.route-link:hover { text-decoration: underline; }
        .callout { border-left: 4px solid #2563eb; background: #eff6ff; padding: 10px 14px; border-radius: 4px; margin: 12px 0; font-size: 13px; color: #1e40af; }
        .callout-warn { border-left-color: #f59e0b; background: #fffbeb; color: #92400e; }
        .callout-danger { border-left-color: #dc2626; background: #fef2f2; color: #991b1b; }
        .callout-tip { border-left-color: #10b981; background: #ecfdf5; color: #065f46; }
        .step-list { counter-reset: step; padding-left: 0; list-style: none; }
        .step-list li { counter-increment: step; position: relative; padding-left: 36px; margin-bottom: 10px; min-height: 28px; }
        .step-list li::before { content: counter(step); position: absolute; left: 0; top: 0; width: 24px; height: 24px; border-radius: 50%; background: #2563eb; color: #fff; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; }
        .pipeline-flow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0; }
        .pipeline-flow .step { background: #eef2ff; border: 1px solid #c7d2fe; color: #3730a3; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; }
        .pipeline-flow .arrow { color: #9ca3af; }
        .field-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 10px 0; }
        .field-table th, .field-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .field-table th { background: #f9fafb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
        .severity-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .sev-low { background: #dbeafe; color: #1e40af; }
        .sev-medium { background: #fef3c7; color: #92400e; }
        .sev-high { background: #fed7aa; color: #9a3412; }
        .sev-critical { background: #fecaca; color: #991b1b; }

        @media (max-width: 1024px) {
            .guide-wrap { grid-template-columns: 1fr; }
            .guide-toc { position: static; max-height: none; }
        }
    </style>

    <div class="guide-wrap">
        {{-- Table of contents --}}
        <aside class="guide-toc">
            <h4>Contents</h4>
            <a href="#intro">1. Introduction</a>
            <a href="#quick-start">2. Quick Start</a>
            <a href="#dashboard">3. Dashboard</a>
            <a href="#cases">4. Cases</a>
            <a href="#case-detail" class="sub">Case Detail</a>
            <a href="#case-actions" class="sub">Actions & Workflow</a>
            <a href="#reports">5. Reports</a>
            <a href="#customers">6. Customers</a>
            <a href="#ips">7. IP Inventory</a>
            <a href="#reputation">8. IP Reputation</a>
            <a href="#abuseipdb">9. AbuseIPDB</a>
            <a href="#email-inbox">10. Email Inbox</a>
            <a href="#reporters">11. Reporters</a>
            <a href="#analytics">12. Analytics</a>
            <a href="#audit">13. Audit Log</a>
            <a href="#users">14. User Management</a>
            <a href="#brands">15. Brands & Email</a>
            <a href="#rules">16. Automation Rules</a>
            <a href="#templates">17. Email Templates</a>
            <a href="#ai-settings">18. AI Settings & Usage</a>
            <a href="#pipeline">19. Pipeline Flow</a>
            <a href="#scoring">20. Severity Scoring</a>
            <a href="#ai-features">21. AI Features</a>
            <a href="#best-practices">22. Best Practices</a>
            <a href="#shortcuts">23. Tips & Shortcuts</a>
        </aside>

        <div class="guide-content">

            {{-- 1. Introduction --}}
            <section id="intro" class="guide-section">
                <h2>1. Introduction</h2>
                <p class="lede">Welcome to <strong>{{ config('app.name', 'AbuseDesk') }}</strong> — your centralized abuse desk for receiving, triaging, scoring and action-closing abuse reports with AI assistance at every key step.</p>
                <p>This guide walks you through every page in the admin console, the case lifecycle, and how the AI helps you work faster. Use the table of contents on the left to jump to a section.</p>

                <h3>What the system does</h3>
                <ul>
                    <li><strong>Ingests</strong> abuse reports from feeds (AbuseIPDB, SpamCop), REST API, public web form, webhooks and the abuse mailbox.</li>
                    <li><strong>Triages</strong> each report with Claude — classifying type, extracting IOCs and filtering noise.</li>
                    <li><strong>Aggregates</strong> related reports into cases, scores severity (0–100), and assigns SLA deadlines.</li>
                    <li><strong>Automates</strong> actions: warns, suspends, blocks IPs, escalates CSAM, and sends notifications.</li>
                    <li><strong>Drafts</strong> reporter and customer communications for agent approval.</li>
                </ul>

                <h3>Roles</h3>
                <ul>
                    <li><strong>Admin</strong> — full access including Settings (Users, Brands &amp; Email, Automation Rules, Email Templates, AI Provider, AI Usage) and System (Horizon).</li>
                    <li><strong>Agent</strong> — case work surface, AI panel, customer notes, reporter replies. <em>No</em> access to Settings or Horizon.</li>
                    <li><strong>Viewer</strong> — read-only across cases, reports, customers, IPs and analytics. Useful for auditors.</li>
                    <li><strong>Reporter</strong> — external, uses the public form or API; not an internal user.</li>
                    <li><strong>Customer</strong> — uses the customer portal to view notices and submit appeals.</li>
                </ul>
            </section>

            {{-- 2. Quick Start --}}
            <section id="quick-start" class="guide-section">
                <h2>2. Quick Start</h2>
                <p class="lede">Your first 10 minutes as a new admin.</p>

                <ol class="step-list">
                    <li>Sign in at <code>/login</code> with your admin credentials. You will land on the <a href="{{ route('admin.dashboard') }}" class="route-link">Dashboard</a>.</li>
                    <li>Open <a href="{{ route('admin.cases.index') }}" class="route-link">Cases &rarr; Case Queue</a> and filter by <code>status: open</code> to see live work.</li>
                    <li>Click any case number (format <code>ABU-YYYY-NNNNN</code>) to open the case detail view.</li>
                    <li>Review the AI Panel — read the AI summary, severity reasoning, and any drafted emails.</li>
                    <li>Take action: change status, assign yourself, add a note, approve or edit the AI draft, then send.</li>
                    <li>Visit <a href="{{ route('admin.analytics') }}" class="route-link">Analytics</a> to see volume, SLA compliance and AI accuracy trends.</li>
                </ol>

                <div class="callout callout-tip">
                    <strong>Tip:</strong> Bookmark the case queue with your most-used filter (e.g. <code>status=open&amp;severity=critical</code>) — the URL preserves filter state.
                </div>
            </section>

            {{-- 3. Dashboard --}}
            <section id="dashboard" class="guide-section">
                <h2>3. Dashboard</h2>
                <p class="lede"><a href="{{ route('admin.dashboard') }}" class="route-link">/admin/dashboard</a> — your daily start page.</p>

                <h3>KPI cards (top row)</h3>
                <ul>
                    <li><strong>Open Cases</strong> — every case with status <code>open</code>. Click to filter the queue.</li>
                    <li><strong>Critical</strong> — cases with severity <span class="severity-pill sev-critical">critical</span> (score &ge; 60, or any CSAM).</li>
                    <li><strong>SLA Breached</strong> — cases past their <code>sla_deadline</code>. Should always be 0 or close to it.</li>
                    <li><strong>Today's Reports</strong> — raw reports received since midnight (before dedup).</li>
                    <li><strong>Total Cases / Total Reports</strong> — lifetime counts.</li>
                    <li><strong>Unprocessed</strong> — reports that reached no terminal outcome; investigate if non-zero.</li>
                    <li><strong>Suspended</strong> — customers currently in suspension state.</li>
                </ul>

                <h3>What to do here</h3>
                <ol>
                    <li>Scan the SLA Breached and Critical KPIs first — they need attention.</li>
                    <li>Review the recent activity timeline for any unexpected automation events.</li>
                    <li>Use KPI cards as filtered shortcuts into the case queue.</li>
                </ol>

                <div class="callout">
                    KPI cards are clickable and route into the relevant filtered list — they're not just for show.
                </div>
            </section>

            {{-- 4. Cases --}}
            <section id="cases" class="guide-section">
                <h2>4. Cases</h2>
                <p class="lede"><a href="{{ route('admin.cases.index') }}" class="route-link">/admin/cases</a> — the primary work surface.</p>

                <p>A <strong>case</strong> is the unit of work. One case bundles all reports targeting the same IP, domain or service for the same abuse type within a deduplication window. Case numbers follow the format <code>ABU-YYYY-NNNNN</code>.</p>

                <h3>Case Queue</h3>
                <p>Filter, sort and bulk-action cases. Filter state is preserved in the URL so you can bookmark or share a view.</p>

                <h4>Filters</h4>
                <table class="field-table">
                    <thead>
                        <tr><th>Filter</th><th>Values</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Status</td><td><code>open</code>, <code>investigating</code>, <code>actioned</code>, <code>resolved</code>, <code>closed</code></td></tr>
                        <tr><td>Severity</td><td><span class="severity-pill sev-low">low</span> <span class="severity-pill sev-medium">medium</span> <span class="severity-pill sev-high">high</span> <span class="severity-pill sev-critical">critical</span></td></tr>
                        <tr><td>Abuse type</td><td>spam, phishing, malware, ddos, csam, copyright, fraud, other</td></tr>
                        <tr><td>Assignee</td><td>Any agent, unassigned, or yourself</td></tr>
                        <tr><td>Date range</td><td>Created / last seen between two dates</td></tr>
                        <tr><td>Search</td><td>Target IP, domain, or case number</td></tr>
                        <tr><td>SLA</td><td><code>breached</code>, <code>at_risk</code>, <code>healthy</code></td></tr>
                    </tbody>
                </table>

                <h4>Bulk actions</h4>
                <ul>
                    <li>Select rows via checkbox, then Bulk &rarr; <strong>Change status</strong>, <strong>Assign</strong>, <strong>Close</strong>, or <strong>Export CSV</strong>.</li>
                    <li>Bulk close requires a closing reason — it is recorded against every selected case.</li>
                </ul>

                {{-- 4a. Case Detail --}}
                <h3 id="case-detail">Case Detail</h3>
                <p>Open any case to see five panels:</p>
                <ol>
                    <li><strong>Header</strong> — case number, status badge, severity score and level, abuse type, target IP/domain, assignee, SLA deadline.</li>
                    <li><strong>Timeline</strong> — chronological log of every action: status change, note, email sent, AI call, suspension. This is your audit trail.</li>
                    <li><strong>Reports tab</strong> — every linked report with source, reporter, AI classification, raw payload and evidence.</li>
                    <li><strong>Evidence tab</strong> — extracted IOCs (IPs, domains, URLs), email headers, attachment downloads.</li>
                    <li><strong>AI Panel</strong> — AI summary, severity re-score reasoning, pattern flags, drafted emails awaiting your approval.</li>
                    <li><strong>Customer info</strong> — linked customer, abuse score, suspension history, related cases.</li>
                </ol>

                {{-- 4b. Actions --}}
                <h3 id="case-actions">Actions & Workflow</h3>
                <p>The standard lifecycle is:</p>
                <div class="pipeline-flow">
                    <span class="step">open</span><span class="arrow">&rarr;</span>
                    <span class="step">investigating</span><span class="arrow">&rarr;</span>
                    <span class="step">actioned</span><span class="arrow">&rarr;</span>
                    <span class="step">resolved</span><span class="arrow">&rarr;</span>
                    <span class="step">closed</span>
                </div>

                <h4>Actions you can take on a case</h4>
                <ul>
                    <li><strong>Change status</strong> — moves the case forward in the lifecycle and stamps the timeline.</li>
                    <li><strong>Assign</strong> — assign to yourself or another agent. Unassigned cases are visible to all.</li>
                    <li><strong>Add note</strong> — internal-only; never shown to reporter or customer.</li>
                    <li><strong>Send email</strong> — to reporter (ack/update/resolved) or customer (warning/suspension). Pick a template or use an AI draft.</li>
                    <li><strong>Suspend customer</strong> — triggers WHMCS API + 60-minute grace period (configurable). Logged to <code>suspension_history</code>.</li>
                    <li><strong>Block IP</strong> — pushes a null-route to the firewall.</li>
                    <li><strong>Escalate</strong> — bumps severity, notifies via Slack/PagerDuty, optionally pages on-call.</li>
                    <li><strong>Re-score with AI</strong> — re-runs the intelligence pass after new reports or evidence.</li>
                    <li><strong>Legal hold</strong> — locks the case from auto-closure and preserves all evidence.</li>
                </ul>

                <div class="callout callout-warn">
                    <strong>CSAM cases</strong> are auto-marked critical, never deduped, and always escalated to law enforcement workflow. Do not close them without supervisor approval.
                </div>

                <div class="callout callout-tip">
                    <strong>Tip:</strong> Use the AI Panel's "Re-score" button after adding new evidence — it accounts for the latest context.
                </div>
            </section>

            {{-- 5. Reports --}}
            <section id="reports" class="guide-section">
                <h2>5. Reports</h2>
                <p class="lede"><a href="{{ route('admin.reports.index') }}" class="route-link">/admin/reports</a> — every raw inbound report, before and after case linkage.</p>

                <p>Reports are the atomic input; cases are the aggregated output. One report may be linked to a case, marked as a duplicate, or filtered out as noise.</p>

                <h3>KPIs on this page</h3>
                <ul>
                    <li><strong>Total Reports</strong> — lifetime ingested.</li>
                    <li><strong>Today's Reports</strong> — last 24h.</li>
                    <li><strong>Unlinked</strong> — reports not yet attached to a case (in-flight or filtered).</li>
                    <li><strong>Noise</strong> — reports with <code>ai_noise_score &gt; 0.8</code> or flagged "not abuse" by AI.</li>
                    <li><strong>Duplicates</strong> — reports linked to an existing report via fingerprint match.</li>
                </ul>

                <h3>Filtering reports</h3>
                <ul>
                    <li>By <strong>source</strong>: feed, api, form, email, manual.</li>
                    <li>By <strong>reporter</strong> (e.g. only AbuseIPDB, only public form).</li>
                    <li>By <strong>AI classification</strong> — type and minimum confidence.</li>
                    <li>By <strong>noise score</strong> — slide to hide low-quality reports.</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Spot-check noise: filter <code>noise_score &gt; 0.8</code> and verify AI is filtering correctly.</li>
                    <li>Re-link an orphan report to the right case via <strong>Link to case</strong>.</li>
                    <li>Manually escalate a borderline report by clicking <strong>Promote to case</strong>.</li>
                </ol>
            </section>

            {{-- 6. Customers --}}
            <section id="customers" class="guide-section">
                <h2>6. Customers</h2>
                <p class="lede"><a href="{{ route('admin.customers.index') }}" class="route-link">/admin/customers</a> — every customer that has had at least one report against them.</p>

                <h3>Customer index</h3>
                <p>Sorted by abuse score by default (worst first). Filter by:</p>
                <ul>
                    <li><strong>Abuse score</strong> band (low / medium / high / critical).</li>
                    <li><strong>Suspended</strong> — currently suspended or not.</li>
                    <li><strong>Has open cases</strong> — any case still <code>open</code> or <code>investigating</code>.</li>
                </ul>

                <h3>Customer profile</h3>
                <p>Click a customer to see their full risk profile:</p>
                <ul>
                    <li><strong>Risk score</strong> (0–100) — cumulative across all cases, decays over time.</li>
                    <li><strong>WHMCS link</strong> — jump to billing for service details.</li>
                    <li><strong>IP addresses & services</strong> assigned to this customer.</li>
                    <li><strong>Case history</strong> — every linked case with severity and outcome.</li>
                    <li><strong>Suspension history</strong> — every suspend / unsuspend with reason and actor.</li>
                    <li><strong>AI risk profile</strong> — Claude-generated summary of behaviour patterns and recommended action.</li>
                    <li><strong>Notes</strong> — internal notes about the customer (across cases).</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Decide on suspension: review AI risk profile + case history + open appeals.</li>
                    <li>Manually adjust the abuse score after resolution (e.g. apply a <em>good behaviour</em> credit).</li>
                    <li>Mark a customer <strong>watched</strong> — flags them for stricter automation thresholds.</li>
                </ol>

                <div class="callout callout-warn">
                    Suspending a customer triggers a real WHMCS API call. Always check there is no recent successful appeal first.
                </div>
            </section>

            {{-- 7. IP Inventory --}}
            <section id="ips" class="guide-section">
                <h2>7. IP Inventory</h2>
                <p class="lede"><a href="{{ route('admin.ips.index') }}" class="route-link">/admin/ips</a> — every IP address allocated to a customer or service.</p>

                <p>The inventory is the join between IPs you operate and the abuse reports against them. Use it to check, before action, whether a target IP is actually yours.</p>

                <h3>Columns</h3>
                <ul>
                    <li><strong>IP / CIDR</strong> — the address or block.</li>
                    <li><strong>Owner</strong> — assigned customer (linked to their profile).</li>
                    <li><strong>Service</strong> — VPS, dedi, mail relay, shared hosting, etc.</li>
                    <li><strong>Reports (30d)</strong> — count of abuse reports against this IP in the last 30 days.</li>
                    <li><strong>Reputation</strong> — current colour (green / yellow / red) — see next section.</li>
                    <li><strong>Status</strong> — active, null-routed, decommissioned.</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Verify ownership before suspending — confirm the IP belongs to a customer in your range.</li>
                    <li>Bulk-update reputation tags after a sweep.</li>
                    <li>Drill into the per-IP timeline (every report, every block).</li>
                </ol>
            </section>

            {{-- 8. IP Reputation --}}
            <section id="reputation" class="guide-section">
                <h2>8. IP Reputation</h2>
                <p class="lede"><a href="{{ route('admin.reputation.index') }}" class="route-link">/admin/reputation</a> — third-party blocklist & reputation health for every IP you own.</p>

                <p>This page shows the public reputation of each IP across major lists (Spamhaus, SORBS, SpamCop, CBL/PSBL, AbuseIPDB confidence). It helps you see external impact, not just inbound reports.</p>

                <h3>Health states</h3>
                <ul>
                    <li><strong>Green</strong> — clean across all checked lists.</li>
                    <li><strong>Yellow</strong> — listed on one or two minor lists, or recent confidence increase.</li>
                    <li><strong>Red</strong> — listed on a major blocklist (Spamhaus SBL/XBL, AbuseIPDB confidence &ge; 75).</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Identify "red" IPs and trace which customer is responsible.</li>
                    <li>After a customer cleanup, click <strong>Request delisting</strong> to file Spamhaus / AbuseIPDB removal forms.</li>
                    <li>Watch the reputation trend chart for early warning of a botnet emerging in your range.</li>
                </ol>

                <div class="callout">
                    Reputation is refreshed every hour by a background job. The "Last checked" column tells you data freshness.
                </div>
            </section>

            {{-- 9. AbuseIPDB --}}
            <section id="abuseipdb" class="guide-section">
                <h2>9. AbuseIPDB</h2>
                <p class="lede"><a href="{{ route('admin.abuseipdb.index') }}" class="route-link">/admin/abuseipdb</a> — direct view of incoming feed reports and outbound submissions.</p>

                <p>AbuseIPDB is the largest external feed. The system polls it every 15 minutes and converts each report into a normalized abuse report.</p>

                <h3>Two-way integration</h3>
                <ul>
                    <li><strong>Inbound</strong> — pulls reports targeting your IP ranges.</li>
                    <li><strong>Outbound</strong> — when you confirm abuse on an IP <em>not</em> in your range (e.g. a phishing source attacking your customers), you can report it back to AbuseIPDB to help others.</li>
                </ul>

                <h3>What you see here</h3>
                <ul>
                    <li>Last poll time and report count delta.</li>
                    <li>Per-IP confidence score from AbuseIPDB.</li>
                    <li>Quota usage against your daily API limit.</li>
                    <li>Outbound submissions log with response codes.</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Review feed health — if last poll is more than 30 minutes ago, check the queue and API key.</li>
                    <li>Manually submit an external attacker IP via <strong>Report IP</strong>.</li>
                    <li>Bulk export AbuseIPDB-linked cases for a takedown report.</li>
                </ol>
            </section>

            {{-- 10. Email Inbox --}}
            <section id="email-inbox" class="guide-section">
                <h2>10. Email Inbox</h2>
                <p class="lede"><a href="{{ route('admin.email.inbox') }}" class="route-link">/admin/email-inbox</a> — every message received at <code>abuse@</code>.</p>

                <p>The mailbox is polled every 5 minutes. Messages in RFC 5965 ARF format are parsed automatically; free-form complaints are sent through Claude for IOC extraction.</p>

                <h3>Columns</h3>
                <ul>
                    <li><strong>From</strong> — reporter email (linked to reporter record if known).</li>
                    <li><strong>Subject</strong> — original email subject.</li>
                    <li><strong>Received at</strong> — parsed timestamp.</li>
                    <li><strong>Linked case</strong> — case number (or "—" if not yet linked).</li>
                    <li><strong>Parse status</strong> — <code>pending</code>, <code>parsed</code>, <code>failed</code>.</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Open a message to view the raw <code>.eml</code>, headers, and AI-extracted IOCs.</li>
                    <li>Manually link an unparsed message to a case.</li>
                    <li>Re-run parsing if the AI extraction looks wrong (corrects in-place).</li>
                    <li>Mark spam / non-abuse to train the noise filter.</li>
                </ol>

                <div class="callout">
                    Original <code>.eml</code> files are stored in S3 with a 90-day retention; the parsed metadata stays forever in <code>abuse_reports</code>.
                </div>
            </section>

            {{-- 11. Reporters --}}
            <section id="reporters" class="guide-section">
                <h2>11. Reporters</h2>
                <p class="lede"><a href="{{ route('admin.reporters.index') }}" class="route-link">/admin/reporters</a> — every entity (feed, person, partner) that has submitted a report.</p>

                <h3>Reporter fields</h3>
                <table class="field-table">
                    <thead>
                        <tr><th>Field</th><th>Meaning</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Type</td><td><code>feed</code> / <code>api</code> / <code>form</code> / <code>email</code> / <code>manual</code></td></tr>
                        <tr><td>Reputation score</td><td>0–100. Higher = more trustworthy reports. Multiplies into severity scoring.</td></tr>
                        <tr><td>Report count</td><td>Total reports submitted (lifetime).</td></tr>
                        <tr><td>Trusted</td><td>Manual flag — bypasses noise filter when true.</td></tr>
                        <tr><td>Blocked</td><td>Manual flag — all reports auto-rejected.</td></tr>
                        <tr><td>API key</td><td>If type is <code>api</code>, the hashed key for authentication.</td></tr>
                    </tbody>
                </table>

                <h3>Common tasks</h3>
                <ol>
                    <li><strong>Promote a reporter to trusted</strong> — for known partners (e.g. a major mail provider).</li>
                    <li><strong>Block a reporter</strong> — for serial false-positive submitters or harassment.</li>
                    <li><strong>Generate a new API key</strong> — for partner integrations. The plain key is shown once; copy immediately.</li>
                    <li><strong>Adjust reputation</strong> — manually reset after disputes; otherwise auto-adjusted by AI.</li>
                </ol>

                <div class="callout callout-warn">
                    Blocking a feed reporter (e.g. AbuseIPDB) stops all inbound reports from that source — almost never what you want. Block individual addresses instead.
                </div>
            </section>

            {{-- 12. Analytics --}}
            <section id="analytics" class="guide-section">
                <h2>12. Analytics</h2>
                <p class="lede"><a href="{{ route('admin.analytics') }}" class="route-link">/admin/analytics</a> — aggregate trends and KPIs.</p>

                <p>Charts are powered by Elasticsearch indices and refreshed in near-real-time. Pick a date range (default last 30 days) at the top.</p>

                <h3>Charts you'll find here</h3>
                <ul>
                    <li><strong>Report volume over time</strong> — by source, stacked.</li>
                    <li><strong>Cases by abuse type</strong> — donut + trend.</li>
                    <li><strong>Severity distribution</strong> — share of low/medium/high/critical.</li>
                    <li><strong>SLA compliance</strong> — % of cases resolved before deadline, broken down by severity.</li>
                    <li><strong>Time to first action</strong> — median &amp; p95.</li>
                    <li><strong>Top offending customers</strong> — by case count and severity.</li>
                    <li><strong>Top sources</strong> — most reported IPs/domains.</li>
                    <li><strong>AI accuracy</strong> — agent agreement rate with AI classification and re-score.</li>
                    <li><strong>Automation hit rate</strong> — % of cases resolved without human action.</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Weekly review: SLA compliance, top customers, automation hit rate.</li>
                    <li>Monthly review: type-mix shifts, AI accuracy, new patterns.</li>
                    <li>Export any chart as PNG or CSV for management reports.</li>
                </ol>

                <div class="callout callout-tip">
                    Use the date-range comparison toggle (current vs previous period) to spot regressions early.
                </div>
            </section>

            {{-- 13. Audit Log --}}
            <section id="audit" class="guide-section">
                <h2>13. Audit Log</h2>
                <p class="lede"><a href="{{ route('admin.audit.index') }}" class="route-link">/admin/audit-log</a> — every state change and AI call, system-wide.</p>

                <p>The audit log is append-only and used for compliance, dispute resolution, and AI debugging. Every row records who did what, when, and (for AI calls) the full prompt and response.</p>

                <h3>What is logged</h3>
                <ul>
                    <li>Every case state change with old/new values.</li>
                    <li>Every email sent (subject, recipient, template, body).</li>
                    <li>Every AI call (prompt, response, token usage, latency).</li>
                    <li>Every suspension and unsuspension.</li>
                    <li>Every login, role change, and settings update.</li>
                </ul>

                <h3>Filtering</h3>
                <ul>
                    <li>By <strong>entity</strong> (case, customer, reporter, user).</li>
                    <li>By <strong>actor</strong> (specific user or "system").</li>
                    <li>By <strong>event</strong> (e.g. <code>case.suspended</code>, <code>ai.triage.completed</code>).</li>
                    <li>By <strong>date range</strong>.</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Investigate an unexpected suspension — find the triggering rule and AI verdict.</li>
                    <li>Pull the full AI prompt + response for a case to debug a misclassification.</li>
                    <li>Export an actor's actions for an internal review.</li>
                </ol>

                <div class="callout callout-danger">
                    Audit log entries cannot be edited or deleted. If something looks wrong, add a correction in the case timeline rather than trying to "fix" the audit log.
                </div>
            </section>

            {{-- 14. User Management --}}
            <section id="users" class="guide-section">
                <h2>14. User Management</h2>
                <p class="lede"><a href="{{ route('admin.users.index') }}" class="route-link">/admin/users</a> — internal staff accounts.</p>

                <h3>Roles &amp; permissions</h3>
                <table class="field-table">
                    <thead>
                        <tr><th>Role</th><th>Capabilities</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Admin</td><td>Everything: cases, reports, customers, IPs, reporters, analytics, audit log <strong>plus</strong> settings (Users, Brands &amp; Email, Automation Rules, Email Templates, AI Provider, AI Usage) and System (Horizon).</td></tr>
                        <tr><td>Agent</td><td>Cases, reports, customers, IPs, reporters, analytics, audit log, AI assistance — but <strong>not</strong> Settings or Horizon. Sidebar hides those sections; direct URL access returns 403.</td></tr>
                        <tr><td>Viewer</td><td>Read-only across cases, reports, customers, IPs, analytics. Useful for auditors. Same Settings/Horizon block as Agent.</td></tr>
                    </tbody>
                </table>

                <h3>Common tasks</h3>
                <ol>
                    <li><strong>Invite a user</strong> — enter email, role; they receive a Breeze invitation.</li>
                    <li><strong>Reset password</strong> — sends reset email; original password is never visible.</li>
                    <li><strong>Disable a user</strong> — preserves their audit trail but blocks login.</li>
                    <li><strong>Change role</strong> — recorded in the audit log.</li>
                </ol>

                <div class="callout callout-warn">
                    Always disable rather than delete a user — deletion would orphan their audit trail.
                </div>
            </section>

            {{-- 15. Brands & Email --}}
            <section id="brands" class="guide-section">
                <h2>15. Brands &amp; Email</h2>
                <p class="lede"><a href="{{ route('admin.brands.index') }}" class="route-link">/admin/brands</a> — outbound email identities and brand-specific routing.</p>

                <p>If you operate multiple hosting brands or domains, this is where you configure each one's "from" identity, signature, support URL, and which email templates apply.</p>

                <h3>Per-brand fields</h3>
                <ul>
                    <li><strong>Display name</strong> — e.g. "AcmeHost Abuse Desk".</li>
                    <li><strong>From address</strong> — e.g. <code>abuse@acmehost.com</code>.</li>
                    <li><strong>Reply-to</strong> — typically same as from.</li>
                    <li><strong>SMTP profile</strong> — pick from configured Mailgun / SES / SMTP profiles.</li>
                    <li><strong>Logo</strong> — embedded into HTML emails.</li>
                    <li><strong>Footer / signature</strong> — legal text appended to every outbound email.</li>
                    <li><strong>Mapped IP ranges</strong> — IPs in these ranges route through this brand.</li>
                </ul>

                <h3>Common tasks</h3>
                <ol>
                    <li>Add a new brand when onboarding a sister company.</li>
                    <li>Update legal footer (e.g. updated registered address).</li>
                    <li>Test send a sample email to verify SPF/DKIM/DMARC alignment.</li>
                </ol>
            </section>

            {{-- 16. Automation Rules --}}
            <section id="rules" class="guide-section">
                <h2>16. Automation Rules</h2>
                <p class="lede"><a href="{{ route('admin.rules.index') }}" class="route-link">/admin/rules</a> — conditional triggers that fire on case events.</p>

                <p>Rules let you encode policy. Each rule has a trigger event, a set of conditions, a list of actions, and a priority. The first matching rule fires (or all matching, depending on configuration).</p>

                <h3>Anatomy of a rule</h3>
                <table class="field-table">
                    <thead>
                        <tr><th>Field</th><th>Description</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Name</td><td>Human label, e.g. <em>"Auto-suspend on high spam score"</em>.</td></tr>
                        <tr><td>Trigger event</td><td><code>report_received</code>, <code>score_changed</code>, <code>time_elapsed</code>, <code>status_changed</code>, <code>appeal_received</code>.</td></tr>
                        <tr><td>Conditions</td><td>List of <code>{field, operator, value}</code> tuples (AND-joined).</td></tr>
                        <tr><td>Abuse types</td><td>Limit to certain types, or <code>null</code> for all.</td></tr>
                        <tr><td>Min score</td><td>Only fire when severity score &ge; threshold.</td></tr>
                        <tr><td>Actions</td><td>Ordered list of jobs to dispatch (suspend, send email, escalate, block IP, add tag).</td></tr>
                        <tr><td>Priority</td><td>Lower number runs first when multiple rules match.</td></tr>
                        <tr><td>Active</td><td>Toggle off to disable without deleting.</td></tr>
                    </tbody>
                </table>

                <h3>Example rules</h3>
                <ul>
                    <li><strong>Critical spam &rarr; suspend</strong>: trigger <code>score_changed</code>, type <code>spam</code>, score &ge; 60 &rarr; <code>SuspendCustomer</code> + notify Slack.</li>
                    <li><strong>CSAM &rarr; immediate escalation</strong>: trigger <code>report_received</code>, type <code>csam</code> &rarr; <code>EscalateCase</code> + email law enforcement contact.</li>
                    <li><strong>SLA breach warning</strong>: trigger <code>time_elapsed</code>, condition "deadline within 1h" &rarr; PagerDuty page.</li>
                    <li><strong>Auto-ack reporter</strong>: trigger <code>report_received</code> &rarr; <code>SendReporterAck</code>.</li>
                </ul>

                <h3>Building a rule</h3>
                <ol class="step-list">
                    <li>Click <strong>New rule</strong> and name it descriptively.</li>
                    <li>Pick the trigger event.</li>
                    <li>Add conditions one by one — fields autocomplete from the case schema.</li>
                    <li>Add actions in execution order. Each action has its own params (e.g. template slug for emails).</li>
                    <li>Set priority and abuse-type filters.</li>
                    <li>Save as <strong>inactive first</strong>, run a dry-run on the last 24h of cases, then activate.</li>
                </ol>

                <div class="callout callout-warn">
                    Rules that suspend or block run with no human approval. <strong>Always dry-run new rules</strong> before activating. The dry-run report shows every case that would have triggered it.
                </div>

                <h3>Trigger metrics</h3>
                <p>Each rule shows <code>last_triggered_at</code> and <code>trigger_count</code>. A rule that has never fired is either too narrow or shadowed by a higher-priority rule.</p>
            </section>

            {{-- 17. Email Templates --}}
            <section id="templates" class="guide-section">
                <h2>17. Email Templates</h2>
                <p class="lede"><a href="{{ route('admin.templates.index') }}" class="route-link">/admin/templates</a> — every outbound email's body and subject.</p>

                <h3>Template types</h3>
                <ul>
                    <li><code>reporter_ack</code> — sent immediately on case creation.</li>
                    <li><code>reporter_status</code> — periodic "still investigating" update.</li>
                    <li><code>reporter_resolved</code> — closure notice with summary.</li>
                    <li><code>customer_warning</code> — first warning before suspension.</li>
                    <li><code>customer_suspension</code> — formal suspension notice with evidence.</li>
                    <li><code>customer_appeal_result</code> — verdict on a customer appeal.</li>
                </ul>

                <h3>Template fields</h3>
                <ul>
                    <li><strong>Slug</strong> — stable identifier referenced in rules and code.</li>
                    <li><strong>Subject</strong> — supports merge variables.</li>
                    <li><strong>HTML &amp; text body</strong> — both are stored; the email client picks the right one.</li>
                    <li><strong>Variables</strong> — declared list of available merge tokens (e.g. <code>@{{ case.number }}</code>, <code>@{{ customer.email }}</code>).</li>
                    <li><strong>Abuse type</strong> — optional filter so you can have type-specific copy.</li>
                    <li><strong>Active</strong> — disable without deleting.</li>
                </ul>

                <h3>Editing</h3>
                <ol>
                    <li>Open a template — uses TinyMCE for HTML and a textarea for plain text.</li>
                    <li>Use the <strong>Insert variable</strong> button — never hand-type tokens.</li>
                    <li>Click <strong>Preview</strong> — renders against a real recent case.</li>
                    <li>Click <strong>Test send</strong> — emails the rendered version to your own address.</li>
                </ol>

                <div class="callout">
                    Templates are versioned. Every save creates a new revision; older versions remain in the audit log so you can see what was actually sent on a given date.
                </div>
            </section>

            {{-- 18. AI Settings & Usage --}}
            <section id="ai-settings" class="guide-section">
                <h2>18. AI Settings &amp; Usage</h2>

                <h3>AI Provider — <a href="{{ route('admin.ai.settings') }}" class="route-link">/admin/ai-settings</a></h3>
                <p>Configure the Claude integration:</p>
                <ul>
                    <li><strong>Model</strong> — defaults to <code>claude-sonnet-4-20250514</code>; pin a version for predictable behaviour.</li>
                    <li><strong>Max tokens</strong> — output cap per call (default 1024).</li>
                    <li><strong>Per-stage toggles</strong> — independently enable triage, intelligence, communications.</li>
                    <li><strong>Auto-apply thresholds</strong> — minimum confidence for AI to act without human approval. Defaults are conservative; raise carefully.</li>
                    <li><strong>API key</strong> — stored encrypted; never displayed after save.</li>
                    <li><strong>Test connection</strong> — sends a no-op call and reports latency.</li>
                </ul>

                <h3>AI Usage — <a href="{{ route('admin.ai.usage') }}" class="route-link">/admin/ai-usage</a></h3>
                <p>Token consumption and cost tracking:</p>
                <ul>
                    <li><strong>Tokens used today / this month</strong> with input vs output split.</li>
                    <li><strong>Estimated cost</strong> using current Anthropic pricing (configurable in <code>config/ai.php</code>).</li>
                    <li><strong>Calls by stage</strong> — triage / intelligence / communications.</li>
                    <li><strong>Top calls by cost</strong> — find expensive prompts to optimise.</li>
                    <li><strong>Failure rate</strong> — % of calls that returned errors or timeouts.</li>
                </ul>

                <div class="callout callout-tip">
                    AI failures never block the pipeline — the system continues with rules-only logic and logs the failure. Keep an eye on the failure rate; sustained &gt;5% means investigate (rate limits, key, network).
                </div>

                <h3>Common tasks</h3>
                <ol>
                    <li>Set a monthly token budget; the system warns at 80% and pauses optional AI calls at 100%.</li>
                    <li>Disable communications AI temporarily during an Anthropic incident — agents fall back to manual templates.</li>
                    <li>Review the "Top calls by cost" table monthly and prune any runaway prompt patterns.</li>
                </ol>
            </section>

            {{-- 19. Pipeline Flow --}}
            <section id="pipeline" class="guide-section">
                <h2>19. Pipeline Flow</h2>
                <p class="lede">How a report becomes a case becomes an action.</p>

                <p>Understanding the pipeline helps you debug why a case ended up in a certain state.</p>

                <h3>Stages</h3>
                <div class="pipeline-flow">
                    <span class="step">Ingestion</span><span class="arrow">&rarr;</span>
                    <span class="step">AI Triage</span><span class="arrow">&rarr;</span>
                    <span class="step">Normaliser</span><span class="arrow">&rarr;</span>
                    <span class="step">Threat Intel</span><span class="arrow">&rarr;</span>
                    <span class="step">Dedup</span><span class="arrow">&rarr;</span>
                    <span class="step">Case Engine</span><span class="arrow">&rarr;</span>
                    <span class="step">AI Intelligence</span><span class="arrow">&rarr;</span>
                    <span class="step">Automation</span><span class="arrow">&rarr;</span>
                    <span class="step">AI Comms</span><span class="arrow">&rarr;</span>
                    <span class="step">Agent</span>
                </div>

                <ol>
                    <li><strong>Ingestion</strong> — receives raw report from feed, API, web form, webhook or email.</li>
                    <li><strong>AI Triage</strong> — Claude classifies type, extracts IOCs, scores noise.</li>
                    <li><strong>Normaliser</strong> — canonical DTO with GeoIP, ASN, WHOIS, rDNS enrichment.</li>
                    <li><strong>Threat Intel</strong> — VirusTotal, Shodan, DNSBL, URLhaus checks.</li>
                    <li><strong>Dedup</strong> — fingerprint hash; duplicates link to existing report instead of creating new.</li>
                    <li><strong>Case Engine</strong> — distributed lock; create new case or attach to open one; compute severity; assign SLA.</li>
                    <li><strong>AI Intelligence</strong> — Claude re-scores using full context; flags patterns; analyses evidence; profiles customer.</li>
                    <li><strong>Automation</strong> — rules fire: suspend, warn, block, escalate, notify.</li>
                    <li><strong>AI Comms</strong> — drafts reporter ack, customer notice, appeal verdict.</li>
                    <li><strong>Agent</strong> — reviews AI work in the admin UI, approves or edits, ships actions.</li>
                </ol>

                <h3>Where to look when something goes wrong</h3>
                <ul>
                    <li>Report stuck in "unprocessed" &rarr; check Horizon queues (<a href="/horizon" class="route-link">/horizon</a>) for failed jobs.</li>
                    <li>Wrong classification &rarr; open the audit log entry for the AI triage call to see prompt + response.</li>
                    <li>Case not auto-suspending &rarr; check rule conditions and priority; verify the score crosses the threshold.</li>
                    <li>No reporter ack sent &rarr; check the brand routing for that target IP and the active <code>reporter_ack</code> template.</li>
                </ul>
            </section>

            {{-- 20. Severity Scoring --}}
            <section id="scoring" class="guide-section">
                <h2>20. Severity Scoring</h2>
                <p class="lede">How a case ends up <span class="severity-pill sev-low">low</span>, <span class="severity-pill sev-medium">medium</span>, <span class="severity-pill sev-high">high</span> or <span class="severity-pill sev-critical">critical</span>.</p>

                <h3>The formula</h3>
                <pre style="background:#0f172a; color:#e2e8f0; padding:14px; border-radius:6px; font-size:12.5px; overflow-x:auto;">score = base_weight[type]
      &times; log10(report_count + 1) &times; 10
      &times; reporter_reputation_multiplier
      &times; recurrence_factor

score = min(score, 100)</pre>

                <h3>Type base weights</h3>
                <p class="text-muted text-sm">Sorted by weight. Higher weight = higher base severity for the same report count.</p>
                <table class="field-table">
                    <thead>
                        <tr><th>Type</th><th>Weight</th><th>Notes</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>csam</td><td>10</td><td>Always critical, skips scoring, immediate escalation.</td></tr>
                        <tr><td>ddos</td><td>8</td><td></td></tr>
                        <tr><td>phishing</td><td>8</td><td></td></tr>
                        <tr><td>law_enforcement</td><td>8</td><td>LE requests, treated as high-priority context.</td></tr>
                        <tr><td>malware</td><td>7</td><td></td></tr>
                        <tr><td>botnet</td><td>7</td><td></td></tr>
                        <tr><td>fraud</td><td>6</td><td></td></tr>
                        <tr><td>intrusion</td><td>6</td><td></td></tr>
                        <tr><td>spam</td><td>3</td><td></td></tr>
                        <tr><td>brute_force</td><td>3</td><td></td></tr>
                        <tr><td>copyright</td><td>2</td><td></td></tr>
                        <tr><td>other</td><td>1</td><td></td></tr>
                    </tbody>
                </table>

                <h3>Multipliers</h3>
                <ul>
                    <li><strong>Reporter reputation</strong> — <code>avg_reporter_reputation / 100</code>. Trusted partners (rep ~95) score higher than unknown form submitters (rep ~30).</li>
                    <li><strong>Recurrence factor</strong> — base 1.0, +0.25 per prior case on the same customer. Repeat offenders escalate faster.</li>
                    <li><strong>AI re-score adjustment</strong> — Claude reviews the full case context and may bump the score up or down with reasoning. Capped at &plusmn;15 points.</li>
                </ul>

                <h3>Thresholds &amp; actions</h3>
                <table class="field-table">
                    <thead>
                        <tr><th>Score</th><th>Level</th><th>Default action</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>0–20</td><td><span class="severity-pill sev-low">low</span></td><td>Log, send reporter ack, no customer action.</td></tr>
                        <tr><td>21–39</td><td><span class="severity-pill sev-medium">medium</span></td><td>Flag for agent review.</td></tr>
                        <tr><td>40–59</td><td><span class="severity-pill sev-high">high</span></td><td>Warn customer, rate-limit service.</td></tr>
                        <tr><td>60–100</td><td><span class="severity-pill sev-critical">critical</span></td><td>Auto-suspend after grace period; page on-call.</td></tr>
                    </tbody>
                </table>

                <h3>SLA deadlines (hours from case open)</h3>
                <ul>
                    <li><span class="severity-pill sev-critical">critical</span> &mdash; <strong>1 hour</strong></li>
                    <li><span class="severity-pill sev-high">high</span> &mdash; <strong>4 hours</strong></li>
                    <li><span class="severity-pill sev-medium">medium</span> &mdash; <strong>24 hours</strong></li>
                    <li><span class="severity-pill sev-low">low</span> &mdash; <strong>72 hours</strong></li>
                </ul>

                <div class="callout callout-tip">
                    Thresholds and SLA hours are configurable per-deployment in <code>config/abusedesk.php</code>. Talk to your admin before changing them.
                </div>
            </section>

            {{-- 21. AI Features --}}
            <section id="ai-features" class="guide-section">
                <h2>21. AI Features</h2>
                <p class="lede">Claude is integrated at three pipeline stages. You'll see its work everywhere in the admin UI.</p>

                <h3>Triage layer (per report)</h3>
                <ul>
                    <li><strong>Classifier</strong> — picks abuse type with confidence score; extracts IPs, domains, URLs.</li>
                    <li><strong>Noise filter</strong> — scores 0.0 (credible) to 1.0 (junk); high-noise reports never create cases.</li>
                    <li><strong>Parser</strong> — pulls structured fields out of free-form complaints.</li>
                </ul>

                <h3>Intelligence layer (per case)</h3>
                <ul>
                    <li><strong>Severity re-scorer</strong> — adjusts the rule-based score based on full context.</li>
                    <li><strong>Pattern detector</strong> — clusters related cases (botnet, coordinated spam, repeat offender, false-positive cluster).</li>
                    <li><strong>Evidence analyser</strong> — summarises email headers, URLs, logs into a 4-sentence agent brief.</li>
                    <li><strong>Customer profiler</strong> — risk level + 3-sentence history summary + recommended action.</li>
                </ul>

                <h3>Communications layer (drafts only)</h3>
                <ul>
                    <li><strong>Reporter reply</strong> — ack, status update, or resolution email.</li>
                    <li><strong>Customer notice</strong> — warning or suspension notice with evidence.</li>
                    <li><strong>Appeal handler</strong> — reads customer appeal, drafts uphold/overturn/investigate verdict for agent.</li>
                    <li><strong>Case summariser</strong> — concise handoff note for shift change or management report.</li>
                </ul>

                <h3>How to interact with AI</h3>
                <ol>
                    <li>Open a case &rarr; <strong>AI Panel</strong> (right side of the case detail page).</li>
                    <li>Read the AI summary, severity reasoning, and any flags.</li>
                    <li>Review the drafted email — edit inline if needed.</li>
                    <li>Click <strong>Approve &amp; send</strong>, <strong>Save edits</strong>, or <strong>Reject &amp; rewrite</strong> (regenerates with feedback).</li>
                    <li>Use <strong>Re-score</strong> after adding evidence to refresh the AI verdict.</li>
                </ol>

                <div class="callout callout-warn">
                    AI drafts are <strong>never auto-sent</strong> for customer suspensions or appeal verdicts — agent approval is mandatory. This is enforced in <code>config/abusedesk.php</code>.
                </div>

                <div class="callout">
                    Every AI call is logged in the audit trail with the full prompt and response. If a draft looks off, you can debug exactly what the model saw.
                </div>
            </section>

            {{-- 22. Best Practices --}}
            <section id="best-practices" class="guide-section">
                <h2>22. Best Practices</h2>

                <h3>Daily routine for an agent</h3>
                <ol class="step-list">
                    <li>Start at the <a href="{{ route('admin.dashboard') }}" class="route-link">Dashboard</a> — check SLA breaches and critical count.</li>
                    <li>Open the <a href="{{ route('admin.cases.index', ['status' => 'open', 'assignee' => 'me']) }}" class="route-link">Case Queue filtered to your assignments</a>.</li>
                    <li>For each case: read AI summary, verify evidence, take action, send reply.</li>
                    <li>Pick up unassigned criticals next.</li>
                    <li>End-of-shift: write a handoff note (use AI Case Summariser).</li>
                </ol>

                <h3>Working with AI</h3>
                <ul>
                    <li><strong>Trust but verify</strong>: AI drafts and classifications are good but not infallible — always read before sending.</li>
                    <li>If the AI is wrong, click <strong>Reject &amp; rewrite</strong> with feedback rather than just editing — this improves future generations through prompt-tuning data.</li>
                    <li>Add new evidence then click <strong>Re-score</strong> — fresh context produces better verdicts.</li>
                </ul>

                <h3>Suspending a customer</h3>
                <ul>
                    <li>Always check the customer profile for: open appeals, recent successful unsuspend, billing notes.</li>
                    <li>For first offences, prefer warning + grace period over immediate suspension.</li>
                    <li>Document the reason in the suspension note — it's used in the customer notice and appeal review.</li>
                </ul>

                <h3>Handling CSAM</h3>
                <ul>
                    <li>Never download or open suspected CSAM media. Evidence is auto-stored encrypted with legal hold.</li>
                    <li>Follow the law-enforcement workflow exactly; do not deviate.</li>
                    <li>Notify your supervisor immediately even if automation has already escalated.</li>
                </ul>

                <h3>Appeals</h3>
                <ul>
                    <li>Read the customer's appeal in full before reading the AI verdict — keeps you unbiased.</li>
                    <li>If the AI says "overturn" but you disagree, document why in the case note.</li>
                    <li>Always respond within 24 hours, even if just to say "received, under review".</li>
                </ul>

                <div class="callout callout-danger">
                    Never use destructive actions (delete case, hard-delete report) to "tidy up" — the audit trail must remain intact. Use status changes and notes instead.
                </div>
            </section>

            {{-- 23. Tips & Shortcuts --}}
            <section id="shortcuts" class="guide-section">
                <h2>23. Tips &amp; Shortcuts</h2>

                <h3>URL filter parameters</h3>
                <p>The case queue and report list preserve filters in the URL. You can build links like:</p>
                <ul>
                    <li><code>/admin/cases?status=open&amp;severity=critical</code> — only critical open cases.</li>
                    <li><code>/admin/cases?sla=breached</code> — every breached SLA, any status.</li>
                    <li><code>/admin/cases?assignee=me&amp;status=investigating</code> — your active work.</li>
                    <li><code>/admin/reports?source=form&amp;noise=high</code> — public form submissions flagged as noise.</li>
                </ul>

                <h3>CSV exports</h3>
                <ul>
                    <li><a href="{{ route('admin.export.cases') }}" class="route-link">/admin/export/cases</a> — export the current filtered case set.</li>
                    <li><a href="{{ route('admin.export.reports') }}" class="route-link">/admin/export/reports</a> — export the current filtered report set.</li>
                </ul>

                <h3>Keyboard</h3>
                <ul>
                    <li><code>?</code> — show keyboard help (where supported).</li>
                    <li><code>j</code> / <code>k</code> — next / previous row in case queue.</li>
                    <li><code>Enter</code> — open the highlighted case.</li>
                    <li><code>g</code> then <code>d</code> — go to dashboard; <code>g</code> then <code>c</code> — case queue.</li>
                </ul>

                <h3>Other useful destinations</h3>
                <ul>
                    <li><a href="/horizon" target="_blank" class="route-link">/horizon</a> — Laravel Horizon dashboard for queue health, failed jobs, throughput.</li>
                    <li><a href="/profile" class="route-link">/profile</a> — change your password, set notification preferences.</li>
                    <li><a href="/report" class="route-link" target="_blank">/report</a> — public abuse form (the version external reporters see).</li>
                </ul>

                <div class="callout callout-tip">
                    <strong>Need help?</strong> Every page has a "Was this useful?" link in the footer. Feedback is reviewed weekly and folded back into this guide.
                </div>
            </section>

        </div>
    </div>

    <script>
        // Smooth scroll for in-page TOC links
        document.querySelectorAll('.guide-toc a[href^="#"]').forEach(link => {
            link.addEventListener('click', e => {
                const target = document.querySelector(link.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    history.replaceState(null, '', link.getAttribute('href'));
                }
            });
        });
    </script>
@endsection
