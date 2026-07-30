<?php

use App\Support\Text\Utf8;

it('leaves valid UTF-8 untouched', function () {
    $ok = 'Twój adres — café 1,000,000 EUR';
    expect(Utf8::clean($ok))->toBe($ok);
});

it('strips invalid UTF-8 bytes so the result is JSON-encodable', function () {
    // 0x80 / 0xFF are not valid standalone UTF-8 — exactly what a
    // Windows-1250 .eml drops into report text.
    $bad = "Fundacja Roberta \x80Boscha \xFF darowizny";

    $clean = Utf8::clean($bad);

    expect(mb_check_encoding($clean, 'UTF-8'))->toBeTrue();
    expect($clean)->toContain('Fundacja Roberta');
    expect($clean)->toContain('darowizny');
    // The whole point: it can now be json_encoded without error.
    expect(json_encode(['evidence' => $clean]))->toBeString();
});

it('returns an empty string unchanged', function () {
    expect(Utf8::clean(''))->toBe('');
});
