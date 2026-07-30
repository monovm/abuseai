<?php

use App\Support\Email\ParsesMimeEmail;
use App\Support\Text\Utf8;

/**
 * Exposes the protected ParsesMimeEmail helpers for direct testing. These
 * feed the email-inbox Livewire state, where a single non-UTF-8 byte in a
 * decoded subject/from throws "Malformed UTF-8" on the next update POST.
 */
function mimeParser(): object
{
    return new class
    {
        use ParsesMimeEmail;

        public function decode(string $header): string
        {
            return $this->decodeMimeHeader($header);
        }

        public function headers(string $headerText): array
        {
            return $this->parseHeaders($headerText);
        }
    };
}

test('ISO-8859-1 Q-encoded subject decodes to valid UTF-8', function () {
    // "café" — é is 0xE9 in ISO-8859-1
    $decoded = mimeParser()->decode('=?ISO-8859-1?Q?caf=E9?=');

    expect(mb_check_encoding($decoded, 'UTF-8'))->toBeTrue();
    expect($decoded)->toBe('café');
});

test('Windows-1252 B-encoded subject decodes to valid UTF-8', function () {
    // "Über" — Ü is 0xDC in Windows-1252
    $encoded = '=?windows-1252?B?'.base64_encode("\xDCber").'?=';
    $decoded = mimeParser()->decode($encoded);

    expect(mb_check_encoding($decoded, 'UTF-8'))->toBeTrue();
    expect($decoded)->toBe('Über');
});

test('adjacent encoded-words drop the separating whitespace (RFC 2047)', function () {
    $encoded = '=?UTF-8?Q?Abuse_?= =?UTF-8?Q?report?=';

    expect(mimeParser()->decode($encoded))->toBe('Abuse report');
});

test('a raw 8-bit Latin-1 subject with no MIME encoding is scrubbed to valid UTF-8', function () {
    // A lone 0xE9 with no =?...?= wrapper — mislabeled/legacy sender.
    $headers = mimeParser()->headers("Subject: caf\xE9 spam report\r\nFrom: a@b.test\r\n");

    expect(mb_check_encoding($headers['subject'], 'UTF-8'))->toBeTrue();
});

test('a lying charset label still yields valid UTF-8 rather than raw bytes', function () {
    // Declares UTF-8 but the bytes are actually Latin-1 (invalid as UTF-8).
    $encoded = '=?UTF-8?Q?caf=E9?=';
    $decoded = mimeParser()->decode($encoded);

    expect(mb_check_encoding($decoded, 'UTF-8'))->toBeTrue();
});

test('parsed headers never contain invalid UTF-8', function () {
    $raw = "Subject: =?ISO-8859-1?Q?Sch=F6ne_Gr=FC=DFe?=\r\n"
        .'From: =?windows-1252?B?'.base64_encode("J\xF6rg")."?= <jorg@example.de>\r\n"
        ."To: abuse@brand.test\r\n";

    $headers = mimeParser()->headers($raw);

    foreach (['subject', 'from', 'to'] as $field) {
        expect(mb_check_encoding($headers[$field], 'UTF-8'))->toBeTrue();
    }
});

test('Utf8::cleanDeep scrubs nested arrays and keys, preserving non-strings', function () {
    $dirty = [
        'subject' => "bad\xE9byte",
        'nested' => ['from' => "x\xFFy", 'count' => 3, 'ok' => true],
        'list' => ["a\xE9", 'clean'],
        'id' => 42,
    ];

    $clean = Utf8::cleanDeep($dirty);

    expect(mb_check_encoding($clean['subject'], 'UTF-8'))->toBeTrue();
    expect(mb_check_encoding($clean['nested']['from'], 'UTF-8'))->toBeTrue();
    expect(mb_check_encoding($clean['list'][0], 'UTF-8'))->toBeTrue();
    expect($clean['nested']['count'])->toBe(3);
    expect($clean['nested']['ok'])->toBeTrue();
    expect($clean['id'])->toBe(42);

    // The whole structure must now be json-encodable without throwing.
    expect(json_encode($clean))->toBeString();
});

test('cleanDeep passes null and scalars through untouched', function () {
    expect(Utf8::cleanDeep(null))->toBeNull();
    expect(Utf8::cleanDeep(5))->toBe(5);
    expect(Utf8::cleanDeep('plain'))->toBe('plain');
});
