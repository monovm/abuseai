<?php

/**
 * Cloudflare edge IP ranges. Used by the TrustProxies middleware so
 * Laravel reads X-Forwarded-For / X-Forwarded-Proto from Cloudflare and
 * reports the real visitor IP and https scheme instead of the CF edge.
 *
 * Source: https://www.cloudflare.com/ips-v4/  and /ips-v6/
 * Refresh occasionally — Cloudflare updates the list a few times a year.
 */
return [
    // Extra proxies in front of the app: "*" to trust any, or a CSV of
    // IPs/CIDRs. Read here (rather than via env() at bootstrap) so the value
    // is baked into the cached config — `config:cache` stops Laravel parsing
    // .env, so a mounted .env file is invisible to a runtime env() call.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'ipv4' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ],
    'ipv6' => [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],
];
