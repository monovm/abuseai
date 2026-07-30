@php
    $brandName = $brand?->name ?? config('app.name', 'Abuse AI');
    $apiBase = url('/api/v1');
    $abuseTypes = collect(\App\Enums\AbuseType::cases())->map(fn ($t) => $t->value)->all();
@endphp

<x-webform.brand-shell
    :title="'API Reference — ' . $brandName"
    heading="API Reference"
    :intro="'REST endpoints for submitting abuse reports to ' . $brandName . ' programmatically.'"
    maxWidth="820px"
>
    <div class="card">
        <h2 style="margin-top:0;">Base URL</h2>
        <pre class="code-block">{{ $apiBase }}</pre>
        <p class="hint" style="margin-top:0.5rem;">All requests go to this host. JSON in, JSON out (UTF-8).</p>

        <h2>Authentication</h2>
        <p>Every request must carry a partner token in the <code class="inline">X-API-Key</code> header.</p>
<pre class="code-block">X-API-Key: abuse_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</pre>
        <p style="margin-top:0.75rem;">
            <a href="{{ route('partner.show') }}" class="btn">Get an API token</a>
        </p>

        <h2>Rate limits</h2>
        <ul class="checklist">
            <li><strong>60 requests/minute</strong> per token (sliding window).</li>
            <li><strong>100 reports per call</strong> on the bulk endpoint.</li>
            <li>Trusted partners get higher limits — request via support.</li>
        </ul>

        <h2>Errors</h2>
        <p>Errors return a JSON body with an <code class="inline">error</code> field and the relevant HTTP status:</p>
        <table class="params">
            <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
            <tbody>
                <tr><td class="name">400</td><td>Malformed request body.</td></tr>
                <tr><td class="name">401</td><td>Missing <code class="inline">X-API-Key</code>.</td></tr>
                <tr><td class="name">403</td><td>Invalid or blocked token.</td></tr>
                <tr><td class="name">422</td><td>Validation failed (see <code class="inline">errors</code> map).</td></tr>
                <tr><td class="name">429</td><td>Rate limit exceeded.</td></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Endpoints</h2>

        {{-- POST /reports --}}
        <div class="endpoint">
            <div class="endpoint-head">
                <span class="method method-post">POST</span>
                <span class="endpoint-path">{{ $apiBase }}/reports</span>
            </div>
            <p style="margin:0.25rem 0 0.5rem;">Submit a single abuse report. Accepts JSON or ARF (RFC 5965) based on <code class="inline">Content-Type</code>.</p>

            <h3 style="margin-bottom:0.25rem;">Request body</h3>
            <table class="params">
                <thead><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                    <tr><td class="name">abuse_type <span class="req">*</span></td><td>string</td><td>One of: {{ implode(', ', $abuseTypes) }}</td></tr>
                    <tr><td class="name">description <span class="req">*</span></td><td>string</td><td>≤ 10000 chars. What happened.</td></tr>
                    <tr><td class="name">target_ip</td><td>string</td><td>IPv4 or IPv6.</td></tr>
                    <tr><td class="name">target_domain</td><td>string</td><td>≤ 255 chars.</td></tr>
                    <tr><td class="name">target_url</td><td>string</td><td>Full URL, ≤ 2048 chars.</td></tr>
                    <tr><td class="name">evidence</td><td>string</td><td>Raw evidence (headers, logs), ≤ 50000 chars.</td></tr>
                    <tr><td class="name">reported_at</td><td>string</td><td>ISO-8601. Defaults to now.</td></tr>
                    <tr><td class="name">headers</td><td>object</td><td>Email headers, free-form keys.</td></tr>
                </tbody>
            </table>

            <h3>Example request</h3>
<pre class="code-block">curl -X POST {{ $apiBase }}/reports \
  -H "X-API-Key: $YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "abuse_type": "phishing",
    "target_ip": "203.0.113.42",
    "target_url": "https://phishing.example/login",
    "description": "Credential-harvesting page mimicking ACME bank",
    "evidence": "Received: from mail.example ...",
    "reported_at": "{{ now()->toIso8601String() }}"
  }'</pre>

            <h3>Example response — 201 Created</h3>
<pre class="code-block">{
  "id": "01HX9Z…",
  "case_number": "ABU-{{ date('Y') }}-00042",
  "message": "Report received. Case: ABU-{{ date('Y') }}-00042"
}</pre>
        </div>

        {{-- POST /reports/bulk --}}
        <div class="endpoint">
            <div class="endpoint-head">
                <span class="method method-post">POST</span>
                <span class="endpoint-path">{{ $apiBase }}/reports/bulk</span>
            </div>
            <p style="margin:0.25rem 0 0.5rem;">Submit up to 100 reports in one call. Body is a JSON array of single-report objects.</p>

            <h3>Example request</h3>
<pre class="code-block">curl -X POST {{ $apiBase }}/reports/bulk \
  -H "X-API-Key: $YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '[
    { "abuse_type": "spam", "target_ip": "198.51.100.7", "description": "Mailbox flood from this IP" },
    { "abuse_type": "ddos", "target_ip": "198.51.100.8", "description": "SYN flood at 12:04 UTC" }
  ]'</pre>

            <h3>Example response — 207 Multi-Status</h3>
<pre class="code-block">{
  "received": 2,
  "results": [
    { "id": "01HX…", "case_number": "ABU-{{ date('Y') }}-00043" },
    { "id": "01HX…", "case_number": "ABU-{{ date('Y') }}-00044" }
  ]
}</pre>
        </div>

        {{-- GET /cases/{number} --}}
        <div class="endpoint">
            <div class="endpoint-head">
                <span class="method method-get">GET</span>
                <span class="endpoint-path">{{ $apiBase }}/cases/{case_number}</span>
            </div>
            <p style="margin:0.25rem 0 0.5rem;">Look up the status of a case you reported. Reporters can only read their own cases.</p>

            <h3>Example request</h3>
<pre class="code-block">curl {{ $apiBase }}/cases/ABU-{{ date('Y') }}-00042 \
  -H "X-API-Key: $YOUR_TOKEN"</pre>

            <h3>Example response — 200 OK</h3>
<pre class="code-block">{
  "case_number": "ABU-{{ date('Y') }}-00042",
  "status": "investigating",
  "abuse_type": "phishing",
  "severity_level": "high",
  "first_seen_at": "…",
  "last_seen_at": "…",
  "report_count": 3
}</pre>
        </div>

        {{-- POST /webhook --}}
        <div class="endpoint">
            <div class="endpoint-head">
                <span class="method method-post">POST</span>
                <span class="endpoint-path">{{ $apiBase }}/webhook/{provider}</span>
            </div>
            <p style="margin:0.25rem 0 0.5rem;">Receive provider-specific webhooks. HMAC signature is verified per provider — no <code class="inline">X-API-Key</code> needed.</p>
            <p class="hint" style="margin:0.25rem 0;">Supported providers: <code class="inline">abuseipdb</code>, <code class="inline">spamhaus</code>, <code class="inline">google_fbl</code>, <code class="inline">microsoft_fbl</code>, <code class="inline">spamcop</code>, <code class="inline">generic_arf</code>.</p>
        </div>
    </div>

    <div class="card" style="text-align:center;">
        <h2 style="margin-top:0;">Ready to start?</h2>
        <p style="color:#6b7280;">Get a token in under a minute. No payment, no waiting list.</p>
        <a href="{{ route('partner.show') }}" class="btn">Register for an API Token</a>
    </div>
</x-webform.brand-shell>
