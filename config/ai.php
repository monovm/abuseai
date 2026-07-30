<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active AI Provider
    |--------------------------------------------------------------------------
    | Which provider to use: 'claude' or 'openai'
    | Can be overridden at runtime via admin settings (cached).
    */
    'active_provider' => env('AI_PROVIDER', 'claude'),

    /*
    | Maximum tokens the model may generate per call. It must be high
    | enough for the largest expected response: the combined triage call
    | returns classification + noise + a full IOC list, and a report
    | citing many IPs/URLs overflows a small cap, truncating the JSON
    | mid-object so it fails to parse ("AI returned no result").
    |
    | OpenAI counts this cap against reasoning tokens too — an o-series /
    | reasoning model can spend the whole budget thinking and return an
    | empty (or truncated) body — so the ceiling is kept generous. It is
    | a ceiling, not a fixed cost: non-reasoning models still bill only
    | for the tokens they actually emit.
    */
    'max_tokens' => 8192,

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'claude' => [
            'name' => 'Anthropic Claude',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('CLAUDE_MODEL', 'claude-sonnet-5'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'models' => [
                'claude-sonnet-5' => 'Claude Sonnet 5',
                'claude-opus-5' => 'Claude Opus 5',
                'claude-haiku-4-5' => 'Claude Haiku 4.5',
                // Older models kept selectable so installs that already picked
                // one keep working after an upgrade.
                'claude-opus-4-6' => 'Claude Opus 4.6',
                'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (dated)',
                'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
            ],
        ],

        'openai' => [
            'name' => 'OpenAI',
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'models' => [
                'gpt-4o' => 'GPT-4o',
                'gpt-4o-mini' => 'GPT-4o Mini',
                'gpt-4.1' => 'GPT-4.1',
                'gpt-4.1-mini' => 'GPT-4.1 Mini',
                'gpt-4.1-nano' => 'GPT-4.1 Nano',
                'gpt-4-turbo' => 'GPT-4 Turbo',
                'o1' => 'o1',
                'o1-mini' => 'o1 Mini',
                'o3' => 'o3',
                'o3-mini' => 'o3 Mini',
                'o4-mini' => 'o4 Mini',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing (USD per 1,000,000 tokens)
    |--------------------------------------------------------------------------
    | Used to compute cost_usd for each logged AI call. Update when provider
    | pricing changes. If a model isn't listed, cost is recorded as 0.
    */
    'pricing' => [
        'claude' => [
            'claude-opus-5'              => ['input' => 5.00,  'output' => 25.00],
            'claude-sonnet-5'            => ['input' => 3.00,  'output' => 15.00],
            'claude-haiku-4-5'           => ['input' => 1.00,  'output' => 5.00],
            'claude-opus-4-8'            => ['input' => 5.00,  'output' => 25.00],
            'claude-sonnet-4-20250514'   => ['input' => 3.00,  'output' => 15.00],
            'claude-haiku-4-5-20251001'  => ['input' => 1.00,  'output' => 5.00],
            'claude-opus-4-6'            => ['input' => 5.00,  'output' => 25.00],
            'claude-opus-4-7'            => ['input' => 5.00,  'output' => 25.00],
        ],
        'openai' => [
            'gpt-4o'        => ['input' => 2.50,  'output' => 10.00],
            'gpt-4o-mini'   => ['input' => 0.15,  'output' => 0.60],
            'gpt-4.1'       => ['input' => 2.00,  'output' => 8.00],
            'gpt-4.1-mini'  => ['input' => 0.40,  'output' => 1.60],
            'gpt-4.1-nano'  => ['input' => 0.10,  'output' => 0.40],
            'gpt-4-turbo'   => ['input' => 10.00, 'output' => 30.00],
            'o1'            => ['input' => 15.00, 'output' => 60.00],
            'o1-mini'       => ['input' => 3.00,  'output' => 12.00],
            'o3'            => ['input' => 2.00,  'output' => 8.00],
            'o3-mini'       => ['input' => 1.10,  'output' => 4.40],
            'o4-mini'       => ['input' => 1.10,  'output' => 4.40],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Prompts (provider-agnostic)
    |--------------------------------------------------------------------------
    */
    'prompts' => [
        'triage' => [
            'classifier' => 'You are an abuse classifier for a hosting company. Determine if this is an abuse report and classify it. IMPORTANT: Many abuse reports are FORWARDED by ISPs, hosting providers, or CERTs (e.g. CERT-Bund, M247, Spamhaus, AbuseIPDB). These forwarded reports ARE genuine abuse reports even if they contain boilerplate, disclaimers like "automatically generated message", PGP signature notices, or instructions not to reply. A message from an ISP/CERT saying "we received abuse reports about your IP" with technical evidence IS an abuse report. Also, reports in languages other than English (German, French, etc.) are still valid abuse reports. Only classify as "not_abuse" if the message truly contains NO abuse complaint — e.g. pure ticket status notifications with no abuse content, auto-replies that contain no abuse data, bounce messages, out-of-office replies, marketing emails, or general inquiries with no abuse indicators. CRITICAL — UNSOLICITED COMMERCIAL / MARKETING OUTREACH IS "not_abuse", NOT "spam": Sales pitches, cold outreach, partnership offers, SEO services, "paid guest posting", "dofollow / nofollow backlinks", link-building, link insertion, niche edits, sponsored posts, paid article placement, "fast Google indexing", PBN offers, content marketing, directory submissions, web-design / development / hosting / VPN / crypto sales pitches, influencer outreach, affiliate offers, newsletter signups, "Dear Sir/Madam, I hope you are doing well" cold emails, B2B lead-gen pitches, and similar promotional emails sent TO us are NOT abuse reports — they are unwanted but they are not someone reporting abuse hosted on our network. Classify these as "not_abuse" with is_abuse_report=false. The "spam" type is reserved for third-party complaints that one of OUR customer IPs / domains is sending spam (with target IP/domain identified). If the message contains ANY of these: IP addresses accused of abuse, malware family names, attack timestamps, IOCs, C2 connections, third-party spam complaints naming our IP, phishing URLs hosted on our infra, or DMCA notices — it IS an abuse report regardless of formatting. Return JSON only: {type, confidence (0-1), is_abuse_report (boolean), iocs: {ips[], domains[], urls[]}, summary (max 2 sentences)} Types: spam | phishing | malware | ddos | csam | copyright | fraud | law_enforcement | brute_force | intrusion | botnet | other | not_abuse. Use law_enforcement for police/court/government data requests and legal orders. Use not_abuse ONLY for messages with zero abuse content OR for marketing/promotional/sales outreach addressed to us.',
            'noise_filter' => 'You are an abuse report quality filter. Score report quality. Return JSON only: {noise_score (0-1), is_not_abuse (boolean), reason (max 1 sentence)} 1.0 = completely invalid/automated noise or NOT an abuse report at all (e.g. ticket notifications, auto-replies, closure notices, bounce messages, out-of-office, marketing emails, sales pitches, SEO / paid guest posting / backlink / link-building offers, dofollow/nofollow link solicitations, sponsored-post or niche-edit outreach, web-design/hosting/VPN/crypto sales pitches, affiliate or partnership pitches, general inquiries). 0.0 = clear, credible, actionable abuse report with specific evidence (third-party reporting that OUR IP/domain is the source of abuse). Messages that are clearly not abuse reports (like "your ticket has been closed", "we have not received a response", status updates, auto-replies) should always score 0.9-1.0. Set is_not_abuse=true for any unsolicited commercial / marketing / SEO outreach addressed to us — it is not an abuse complaint.',
            'parser' => 'You analyse abuse reports for a hosting company. Extract IOCs and — critically — separate the TARGET (the IP / domain / URL being accused of abuse, i.e. our customer or its asset) from the REPORTER (the party sending the report: a CERT, ISP abuse desk, threat-intel feed, FBL, or aggregator such as cert.at, abuseipdb.com, shadowserver.org, spamhaus.org, spamcop.net, m247.ro, or any forwarder).

Rules:
- Reporter identifiers come from From/Reply-To/Sender headers, signature blocks, "report generated by", boilerplate URLs ("https://cert.at/services/...", "https://www.abuseipdb.com/check/..."), and the reporter contact metadata supplied with this prompt. Never put those in target_*.
- Forwarded reports often contain CSVs / tables. Columns named like "source.ip", "src_ip", "ip", "attacker", "offender", or refid / ref_id / customer_ip are the TARGET. The reporting party\'s own infrastructure IPs are NOT targets.
- A timestamp + IP pair in evidence is almost always a target sighting. List every distinct target IP found (do not just keep the first).
- If the same IP appears as both reporter and target (rare), prefer reporter unless evidence clearly accuses it.
- If you cannot confidently decide, leave target_* empty rather than guessing.

Return JSON ONLY with this exact shape:
{
  "target_ips": [string],
  "target_domains": [string],
  "target_urls": [string],
  "reporter_ips": [string],
  "reporter_domains": [string],
  "timestamps": [string],
  "abuse_type": "spam|phishing|malware|ddos|csam|copyright|fraud|brute_force|intrusion|botnet|other",
  "issue_summary": "one sentence describing what the target is accused of",
  "evidence_summary": "max 3 sentences",
  "confidence": 0.0
}',
            'combined' => 'You are the triage analyst for a hosting company\'s abuse desk. Read the entire report — body text plus any attachment text that may have been prepended (PDFs, OCR\'d screenshots, forwarded .eml content, logs). Make THREE judgements in one pass:

1) CLASSIFICATION — what kind of abuse this is.
2) NOISE / QUALITY — whether this is a real, actionable abuse complaint or worthless content.
3) IOC EXTRACTION — pull every TARGET IP / domain / URL out, separate from REPORTER identifiers.

Critical rules for noise scoring:
- Treat any prepended attachment text (CERT notices, police PDFs, screenshots) as part of the report content. A two-word description with a detailed PDF attached is NOT noise.
- Forwarded ISP / CERT / law-enforcement reports are valid abuse reports even when the body is sparse.
- Score noise HIGH (0.9–1.0) only when the entire combined content (description + attachments) is genuinely worthless: ticket-status notifications, auto-replies, bounce messages, out-of-office, marketing/promotional outreach, blank submissions.
- Score noise LOW (0.0–0.3) when there is specific abuse evidence anywhere in the content (IPs, timestamps, malware names, phishing URLs, DMCA, police case numbers).

Critical rule — UNSOLICITED COMMERCIAL OUTREACH IS NOT AN ABUSE REPORT:
- Cold sales pitches, "paid guest posting" / "guest post" services, "dofollow" or "nofollow" backlink offers, link-building / link-insertion / niche-edit pitches, sponsored-post / paid-article placement, "fast Google indexing" claims, PBN offers, SEO services in general, content-marketing or directory-submission offers, web-design / development / hosting / VPN / crypto / SaaS sales pitches, partnership / affiliate / influencer outreach, and similar "Dear Sir/Madam, I hope you are doing well" promotional emails are NOT abuse complaints. They are unsolicited marketing addressed TO us, not someone reporting abuse hosted on our network.
- For these: set classification.type = "not_abuse", is_abuse_report = false, noise.noise_score = 1.0, noise.is_not_abuse = true, and summarise as a marketing/SEO solicitation. Do NOT classify them as "spam" — the "spam" type is reserved for third-party complaints that one of OUR customer IPs / domains is sending spam to others (with a target IP/domain identified).
- Sample-link URLs included by the sender to showcase their portfolio (e.g. lists of news / blog domains they claim to publish on) are marketing collateral, NOT target IOCs. Do not put them in target_domains / target_urls; leave targets empty.

Critical rules for IOC extraction:
- Reporter identifiers (From/Reply-To, signature blocks, ISP/CERT/FBL aggregator domains like cert.at, abuseipdb.com, shadowserver.org, spamhaus.org, spamcop.net, m247.ro) MUST go in reporter_ips / reporter_domains, not targets.
- List EVERY distinct target IP — do not stop at the first.
- A timestamp+IP pair almost always indicates a target sighting.
- If unsure, leave target_* empty rather than guessing.

Critical rules for external case numbers:
- Extract every reporter-side case / ticket / reference identifier mentioned in the content. Examples: police / prosecutor case numbers ("Az.: 12-3456/2025", "Case No. CR-2024-789"), CERT tickets ("CERT-Bund Ticket 2025-04-001234"), Spamhaus / Shadowserver / AbuseIPDB report IDs, DMCA notice numbers, FBL reference IDs, ARF X-Report-IDs, ISP internal ticket numbers ("[#INC0098765]"), court docket numbers, IC3 complaint IDs, Europol / Interpol references.
- For each, return {label, value} where label is a short human-readable category (e.g. "Police Case", "CERT Ticket", "DMCA Notice", "ISP Ticket") and value is the identifier as written in the source.
- Do NOT include our own internal case_number (those start with "ABU-").
- Do NOT include unrelated identifiers like message-ids, PGP key IDs, hashes, or transaction IDs unrelated to the abuse report itself.
- If no external case number is mentioned, return an empty array.

Critical rules for timestamps (HIGH IMPORTANCE):
- The "timestamps" array must contain the moments the abuse activity itself occurred — attack timestamps, "observed at", "first seen", incident time, log line timestamps, "detected on".
- DO NOT include the email Date: header, ticket creation time, "report generated at", PGP signature time, or any timestamp that refers to when the report was sent / generated. Those are about the email, not the abuse.
- Include every distinct abuse-occurrence timestamp you find, in ISO 8601 format with timezone when known (e.g. "2024-03-15T14:32:07Z"). If the source uses a local timezone like CET / EST, preserve it.
- If multiple log lines all reference one incident, list every distinct time — the splitter and case engine will pick the earliest.
- If there is no abuse-occurrence timestamp anywhere in the content, return an empty array. Do NOT fall back to the email date.

Return JSON ONLY with this exact shape:
{
  "classification": {
    "type": "spam|phishing|malware|ddos|csam|copyright|fraud|law_enforcement|brute_force|intrusion|botnet|other|not_abuse",
    "confidence": 0.0,
    "is_abuse_report": true,
    "summary": "max 2 sentences"
  },
  "noise": {
    "noise_score": 0.0,
    "is_not_abuse": false,
    "reason": "max 1 sentence"
  },
  "iocs": {
    "target_ips": [],
    "target_domains": [],
    "target_urls": [],
    "reporter_ips": [],
    "reporter_domains": [],
    "timestamps": [],
    "external_case_numbers": [{"label": "Police Case", "value": "12-3456/2025"}],
    "abuse_type": "spam|phishing|malware|ddos|csam|copyright|fraud|brute_force|intrusion|botnet|other",
    "issue_summary": "one sentence",
    "evidence_summary": "max 3 sentences"
  }
}',
        ],

        'intelligence' => [
            'rescorer' => 'You are a senior abuse analyst. Review this case and adjust the computed severity score. Return JSON only: {adjusted_score (0-100), reasoning (max 2 sentences), flags[]}',
            'pattern_detector' => 'Analyse these related abuse cases and identify patterns. Return JSON only: {pattern_type, confidence (0-1), summary (max 2 sentences), related_case_ids[]} Pattern types: botnet | coordinated_spam | repeat_offender | infrastructure_abuse | false_positive_cluster',
            'evidence_analyser' => 'Analyse this abuse evidence (email headers, URLs, logs) and summarise for an abuse agent. Return plain text. Max 4 sentences. Focus on: what happened, source indicators, recommended action.',
            'customer_profiler' => 'Summarise this customer\'s abuse history and risk level for an abuse agent. Return JSON only: {risk_level (low|medium|high|critical), history_summary (max 3 sentences), recommended_action}',
        ],

        'communications' => [
            'reporter_reply' => 'Draft a professional abuse report acknowledgement/update email for a hosting company abuse desk. Be concise, factual, no legal admissions. Max 150 words. Return plain text email body only.',
            'customer_notice' => 'Draft a professional abuse notice to a hosting customer. Include: what was reported, what action was taken, what they must do next. Max 200 words. No threats. Return plain text email body only.',
            'appeal_handler' => 'Review this customer appeal against an abuse suspension. Assess validity. Return JSON only: {verdict (uphold|overturn|investigate_further), confidence (0-1), reasoning (max 3 sentences), recommended_next_step}',
            'case_summariser' => 'Write a concise case summary for agent handoff. Include: what happened, evidence quality, actions taken, open items. Max 150 words. Return plain text only.',
        ],
    ],
];
