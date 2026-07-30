<?php

use App\Support\Email\EmailTextExtractor;
use App\Support\IpHelpers;

test('allText includes subject so IPs in subject line are extractable', function () {
    $email = [
        'subject' => 'Abuse from 203.0.113.45',
        'body' => 'Please investigate this host.',
        'raw' => "Subject: Abuse from 203.0.113.45\r\n\r\nPlease investigate this host.",
    ];

    $text = EmailTextExtractor::allText($email);

    expect($text)->toContain('203.0.113.45');
    expect(IpHelpers::extractIps($text))->toContain('203.0.113.45');
});

test('allText includes body', function () {
    $email = [
        'subject' => 'abuse report',
        'body' => 'The offending IP is 198.51.100.7',
        'raw' => '',
    ];

    expect(IpHelpers::extractIps(EmailTextExtractor::allText($email)))
        ->toContain('198.51.100.7');
});

test('allText decodes base64 text attachments so IPs in them are found', function () {
    $attachmentBody = base64_encode("Forwarded log:\nattacker IP: 192.0.2.99\n");

    $raw = "From: r@example.com\r\n"
        . "Subject: complaint\r\n"
        . "Content-Type: multipart/mixed; boundary=BOUND\r\n\r\n"
        . "--BOUND\r\n"
        . "Content-Type: text/plain\r\n\r\n"
        . "See attached log.\r\n"
        . "--BOUND\r\n"
        . "Content-Type: text/plain; name=\"log.txt\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $attachmentBody . "\r\n"
        . "--BOUND--\r\n";

    $email = [
        'subject' => 'complaint',
        'body' => 'See attached log.',
        'raw' => $raw,
    ];

    $text = EmailTextExtractor::allText($email);

    expect($text)->toContain('192.0.2.99');
    expect(IpHelpers::extractIps($text))->toContain('192.0.2.99');
});

test('allText decodes quoted-printable attachments', function () {
    $raw = "Content-Type: multipart/mixed; boundary=B\r\n\r\n"
        . "--B\r\n"
        . "Content-Type: text/plain\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . "attacker is 203.0.113.5=\r\n"
        . " confirmed\r\n"
        . "--B--\r\n";

    $email = ['subject' => '', 'body' => '', 'raw' => $raw];

    expect(IpHelpers::extractIps(EmailTextExtractor::allText($email)))
        ->toContain('203.0.113.5');
});

test('allText skips binary attachments', function () {
    // Pretend image content. If we accidentally decoded it as text and
    // ran an IP regex over the bytes, we could match noise — but with
    // the binary skip we get nothing.
    $imageBytes = base64_encode(random_bytes(64));

    $raw = "Content-Type: multipart/mixed; boundary=B\r\n\r\n"
        . "--B\r\n"
        . "Content-Type: text/plain\r\n\r\n"
        . "no ips here\r\n"
        . "--B\r\n"
        . "Content-Type: image/png; name=\"x.png\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $imageBytes . "\r\n"
        . "--B--\r\n";

    $email = ['subject' => '', 'body' => '', 'raw' => $raw];

    $text = EmailTextExtractor::allText($email);

    expect($text)->not->toContain($imageBytes);
});

test('allText handles a non-multipart email', function () {
    $email = [
        'subject' => 'plain note',
        'body' => 'address 10.0.0.5 attacking',
        'raw' => "Subject: plain note\r\n\r\naddress 10.0.0.5 attacking",
    ];

    expect(IpHelpers::extractIps(EmailTextExtractor::allText($email)))
        ->toContain('10.0.0.5');
});
