<?php

use App\Support\Email\ForwardedReporterDetector;

test('returns null for plain non-forwarded email', function () {
    $email = [
        'from' => 'Alice <alice@example.com>',
        'subject' => 'Abuse from your network',
        'body' => "Hi,\n\nYour IP 203.0.113.5 is scanning my servers.\n\nThanks,\nAlice",
        'raw' => '',
    ];

    expect(ForwardedReporterDetector::detect($email))->toBeNull();
});

test('detects upstream sender from gmail-style forwarded block', function () {
    $body = <<<BODY
    Hi team,

    Please look into this report below.

    ---------- Forwarded message ---------
    From: Abuse Desk <abuse@spamhaus.org>
    Date: Mon, 12 May 2025 at 09:14
    Subject: SBL listing for 203.0.113.5
    To: customer@hosting.example

    Your IP 203.0.113.5 has been listed on SBL...
    BODY;

    $email = [
        'from' => 'Customer <customer@hosting.example>',
        'subject' => 'Fwd: SBL listing for 203.0.113.5',
        'body' => $body,
        'raw' => '',
    ];

    $upstream = ForwardedReporterDetector::detect($email);

    expect($upstream)->not->toBeNull();
    expect($upstream['email'])->toBe('abuse@spamhaus.org');
    expect($upstream['name'])->toBe('Abuse Desk');
    expect($upstream['source'])->toBe('forwarded_block');
});

test('detects upstream sender from outlook header block', function () {
    $body = <<<BODY
    Forwarding for your attention.

    From: SOC Analyst <soc@cert.example.org>
    Sent: Tuesday, May 6, 2025 14:22
    To: support@hosting.example
    Subject: Botnet C2 on 198.51.100.10

    Hello, we detected botnet C2 activity from your host...
    BODY;

    $email = [
        'from' => 'Customer <customer@hosting.example>',
        'subject' => 'Fwd: Botnet C2 on 198.51.100.10',
        'body' => $body,
        'raw' => '',
    ];

    $upstream = ForwardedReporterDetector::detect($email);

    expect($upstream)->not->toBeNull();
    expect($upstream['email'])->toBe('soc@cert.example.org');
    expect($upstream['source'])->toBe('outlook_block');
});

test('detects upstream sender from message/rfc822 attachment', function () {
    $inner = "From: CERT Team <cert@upstream.example>\r\n"
        . "Subject: Abuse report\r\n"
        . "Date: Mon, 1 May 2025 10:00:00 +0000\r\n"
        . "To: customer@hosting.example\r\n\r\n"
        . "Your IP 203.0.113.99 is attacking us.";

    $raw = "From: Customer <customer@hosting.example>\r\n"
        . "Subject: Fwd: Abuse report\r\n"
        . "Content-Type: multipart/mixed; boundary=BOUND\r\n\r\n"
        . "--BOUND\r\n"
        . "Content-Type: text/plain\r\n\r\n"
        . "FYI — original below.\r\n"
        . "--BOUND\r\n"
        . "Content-Type: message/rfc822\r\n\r\n"
        . $inner . "\r\n"
        . "--BOUND--\r\n";

    $email = [
        'from' => 'Customer <customer@hosting.example>',
        'subject' => 'Fwd: Abuse report',
        'body' => 'FYI — original below.',
        'raw' => $raw,
    ];

    $upstream = ForwardedReporterDetector::detect($email);

    expect($upstream)->not->toBeNull();
    expect($upstream['email'])->toBe('cert@upstream.example');
    expect($upstream['name'])->toBe('CERT Team');
    expect($upstream['source'])->toBe('rfc822_attachment');
});

test('detects upstream sender from Resent-From header', function () {
    $raw = "From: list-manager@hosting.example\r\n"
        . "Resent-From: Original Reporter <orig@reporter.example>\r\n"
        . "Subject: Forwarded complaint\r\n\r\n"
        . "Body content";

    $email = [
        'from' => 'list-manager@hosting.example',
        'subject' => 'Forwarded complaint',
        'body' => 'Body content',
        'raw' => $raw,
        'headers' => ['from' => 'list-manager@hosting.example'],
    ];

    $upstream = ForwardedReporterDetector::detect($email);

    expect($upstream)->not->toBeNull();
    expect($upstream['email'])->toBe('orig@reporter.example');
    expect($upstream['source'])->toBe('forwarding_header');
});

test('rfc822 attachment beats body block when both present', function () {
    $inner = "From: real@upstream.example\r\nSubject: abuse\r\n\r\nbody";

    $raw = "From: Customer <customer@hosting.example>\r\n"
        . "Subject: Fwd: abuse\r\n"
        . "Content-Type: multipart/mixed; boundary=BOUND\r\n\r\n"
        . "--BOUND\r\n"
        . "Content-Type: text/plain\r\n\r\n"
        . "---------- Forwarded message ---------\r\n"
        . "From: fake@signature.example\r\n"
        . "To: someone\r\n\r\n"
        . "--BOUND\r\n"
        . "Content-Type: message/rfc822\r\n\r\n"
        . $inner . "\r\n"
        . "--BOUND--\r\n";

    $email = [
        'from' => 'customer@hosting.example',
        'subject' => 'Fwd: abuse',
        'body' => "---------- Forwarded message ---------\nFrom: fake@signature.example\nTo: someone",
        'raw' => $raw,
    ];

    $upstream = ForwardedReporterDetector::detect($email);

    expect($upstream['email'])->toBe('real@upstream.example');
    expect($upstream['source'])->toBe('rfc822_attachment');
});

test('handles MIME-encoded display name', function () {
    $body = <<<BODY
    ---------- Forwarded message ---------
    From: =?UTF-8?B?w4ZsZmEgw5ZyaWdpbmFs?= <alfa@upstream.example>
    Date: Mon, 12 May 2025
    To: me@hosting.example
    Subject: heads up

    body
    BODY;

    $email = [
        'from' => 'customer@hosting.example',
        'subject' => 'Fwd: heads up',
        'body' => $body,
        'raw' => '',
    ];

    $upstream = ForwardedReporterDetector::detect($email);

    expect($upstream['email'])->toBe('alfa@upstream.example');
    expect($upstream['name'])->toContain('Ælfa');
});

test('ignores non-forwarded mail even if body mentions a From line', function () {
    $email = [
        'from' => 'Alice <alice@example.com>',
        'subject' => 'Question about your service',
        'body' => "Hi,\n\nFrom: me you'll see the bill is wrong.\n\nThanks",
        'raw' => '',
    ];

    expect(ForwardedReporterDetector::detect($email))->toBeNull();
});
