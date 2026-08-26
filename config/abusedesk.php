<?php

return [
    'scoring' => [
        'type_weights' => [
            'csam' => 10,
            'ddos' => 8,
            'phishing' => 8,
            'law_enforcement' => 8,
            'malware' => 7,
            'botnet' => 7,
            'fraud' => 6,
            'intrusion' => 6,
            'spam' => 3,
            'brute_force' => 3,
            'copyright' => 2,
            'other' => 1,
        ],
        'thresholds' => [
            'flag' => 20,
            'warn' => 40,
            'suspend' => 60,
        ],
        'recurrence_increment' => 0.25,
    ],

    'retention' => [
        // Raw .eml archives (emails/Y/m) and extracted attachments
        // (evidence/Y/m) are written at intake and never re-read once a
        // case is closed. abuse:prune-emails deletes them past this age.
        'email_archive_days' => (int) env('EMAIL_ARCHIVE_RETENTION_DAYS', 90),
    ],

    'sla' => [
        'critical' => 1,   // hours
        'high' => 4,
        'medium' => 24,
        'low' => 72,
    ],

    'reputation' => [
        'case_threshold' => 30,       // Below this → auto-create case (0-100, lower = worse)
        'recovery_threshold' => 50,   // Above this → reset case flag, allow new case
        'weights' => [
            'abuseipdb' => 0.5,       // AbuseIPDB score × this = penalty (max -50)
            'dnsbl_per_list' => 15,    // Per blacklist listing penalty (max -40 total)
            'dnsbl_max' => 40,         // Cap for DNSBL penalties
            'open_cases' => 10,        // Per open case penalty (max -20 total)
            'open_cases_max' => 20,    // Cap for open case penalties
            'virustotal' => 0.3,       // VT malicious ratio × this (max -30)
            'shodan_vulns' => 5,       // Per vulnerability penalty (max -25)
            'snds' => 1.0,             // SNDS penalty multiplier (max -30)
        ],
    ],

    'collectors' => [
        'snds_key' => env('SNDS_KEY'),  // Microsoft SNDS automated data key
    ],

    'dedup' => [
        'window_hours' => 24,
    ],

    'suspension' => [
        'grace_minutes' => 60,
    ],

    'feeds' => [
        'abuseipdb' => [
            'enabled' => env('ABUSEIPDB_ENABLED', true),
            'api_key' => env('ABUSEIPDB_KEY'),
            'poll_minutes' => 15,
        ],
        'dnsbl' => [
            'enabled' => env('DNSBL_ENABLED', true),
            'check_hours' => 12,
        ],
    ],

    'webhooks' => [
        'abuseipdb' => ['secret' => env('WEBHOOK_SECRET_ABUSEIPDB')],
        'spamhaus' => ['secret' => env('WEBHOOK_SECRET_SPAMHAUS')],
        'google_fbl' => ['secret' => env('WEBHOOK_SECRET_GOOGLE_FBL')],
        'microsoft_fbl' => ['secret' => env('WEBHOOK_SECRET_MICROSOFT_FBL')],
        'spamcop' => ['secret' => env('WEBHOOK_SECRET_SPAMCOP')],
        // Shared secret for the MTA-pipe inbound-email endpoint. The
        // sending side passes this in X-Inbound-Secret. Empty disables
        // the endpoint entirely (returns 503).
        'inbound_email_secret' => env('WEBHOOK_INBOUND_EMAIL_SECRET'),
    ],

    'email' => [
        'mandrill_key' => env('MANDRILL_KEY'),
    ],

    'threat_intel' => [
        'virustotal_key' => env('VIRUSTOTAL_KEY'),
        'shodan_key' => env('SHODAN_KEY'),
    ],

    'ai' => [
        // The model is configured per provider in config/ai.php.
        'max_tokens' => 1024,
        'log_calls' => true,
        'max_concurrent' => 10,
        'retry_attempts' => 3,
    ],

    'whmcs' => [
        'url' => env('WHMCS_API_URL'),             // e.g. https://billing.example.com/includes/api.php
        'api_identifier' => env('WHMCS_API_IDENTIFIER'),
        'api_secret' => env('WHMCS_API_SECRET'),
        // Default department id for auto-opened abuse tickets. Override per brand
        // via brand.whmcs_config.ticket_department_id.
        'ticket_department_id' => env('WHMCS_TICKET_DEPARTMENT_ID'),
        // WHMCS admin user the ticket is opened on behalf of. Some installs
        // require this for OpenTicket to attribute the ticket correctly and
        // bypass per-department permission checks.
        'admin_username' => env('WHMCS_ADMIN_USERNAME', '43'),
    ],

    'virtualizor' => [
        'url' => env('VIRTUALIZOR_URL'),            // e.g. https://panel.example.com:4085
        'api_key' => env('VIRTUALIZOR_API_KEY'),
        'api_pass' => env('VIRTUALIZOR_API_PASS'),
    ],

    'csam' => [
        'law_enforcement_email' => env('CSAM_LE_EMAIL'),
        'ncmec_api_key' => env('NCMEC_KEY'),
    ],

    // Reporters whose email domain matches any of these patterns are auto-tagged
    // as law enforcement. Reports from LE-tagged reporters bypass the noise filter
    // and always open a case (even if the target IP is not in inventory).
    // Supports bare domains ("fbi.gov") and wildcard TLDs ("*.police.uk").
    // Additional domains can be provided via LAW_ENFORCEMENT_DOMAINS (comma-separated).
    'law_enforcement' => [
        'reporter_domains' => array_values(array_filter(array_map('trim', array_merge([
            'fbi.gov',
            'ic3.gov',
            'justice.gov',
            'usss.dhs.gov',
            'dhs.gov',
            'interpol.int',
            'europol.europa.eu',
            'ncmec.org',
            'gendarmerie.interieur.gouv.fr',
            'police.gc.ca',
            '*.police.uk',
            '*.pnij.justice.gouv.fr',
        ], explode(',', (string) env('LAW_ENFORCEMENT_DOMAINS', '')))))),
    ],
];
