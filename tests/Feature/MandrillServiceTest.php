<?php

use App\Models\Brand;
use App\Models\EmailConnection;
use App\Models\SentEmail;
use App\Services\Email\BrandSmtpMailer;
use App\Services\Email\EmailTransportSettings;
use App\Services\Email\MandrillService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function mandrill(): MandrillService
{
    // Constructor reads the key from config, so set it before instantiating.
    config(['abusedesk.email.mandrill_key' => 'test-key']);

    return new MandrillService;
}

function sendTestEmail(?Brand $brand = null): ?SentEmail
{
    return mandrill()->send(
        toEmail: 'reporter@example.com',
        toName: 'Reporter',
        subject: 'Re: abuse case',
        htmlBody: '<p>hello</p>',
        brand: $brand,
    );
}

function brandWithSmtpFallback(): Brand
{
    return Brand::create([
        'name' => 'Acme',
        'slug' => 'acme',
        'from_name' => 'Acme Abuse',
        'from_email' => 'abuse@acme.test',
        'is_active' => true,
        'smtp_config' => [
            'smtp_host' => 'mail.acme.test',
            'smtp_username' => 'abuse@acme.test',
            'smtp_password' => 'secret',
        ],
    ]);
}

test('an API error object is recorded as failed, not a silent success', function () {
    // Mandrill returns an error OBJECT (not a list) with a 5xx on a bad key /
    // unverified sender. This is the "shows success but nothing sent" bug.
    Http::fake([
        'mandrillapp.com/*' => Http::response([
            'status' => 'error',
            'code' => -1,
            'name' => 'Invalid_Key',
            'message' => 'Invalid API key',
        ], 500),
    ]);

    $sent = sendTestEmail();

    expect($sent->status)->toBe('failed');
    expect($sent->wasAccepted())->toBeFalse();
    expect($sent->message_id)->toBeNull();
    expect($sent->failureReason())->toBe('Invalid API key');
});

test('a rejected recipient is not treated as accepted', function () {
    // 200 OK, but the message did not go out (e.g. unsigned sending domain).
    Http::fake([
        'mandrillapp.com/*' => Http::response([[
            'email' => 'reporter@example.com',
            'status' => 'rejected',
            'reject_reason' => 'unsigned',
            '_id' => 'abc123',
        ]], 200),
    ]);

    $sent = sendTestEmail();

    expect($sent->status)->toBe('rejected');
    expect($sent->wasAccepted())->toBeFalse();
    expect($sent->failureReason())->toBe('unsigned');
});

test('an invalid recipient is not treated as accepted', function () {
    // "invalid" responses never appear in Mandrill's outbound activity log.
    Http::fake([
        'mandrillapp.com/*' => Http::response([[
            'email' => 'reporter@example.com',
            'status' => 'invalid',
            '_id' => 'def456',
        ]], 200),
    ]);

    $sent = sendTestEmail();

    expect($sent->status)->toBe('invalid');
    expect($sent->wasAccepted())->toBeFalse();
});

test('a sent recipient is recorded as accepted with its message id', function () {
    Http::fake([
        'mandrillapp.com/*' => Http::response([[
            'email' => 'reporter@example.com',
            'status' => 'sent',
            '_id' => 'ghi789',
            'reject_reason' => null,
        ]], 200),
    ]);

    $sent = sendTestEmail();

    expect($sent->status)->toBe('sent');
    expect($sent->wasAccepted())->toBeTrue();
    expect($sent->message_id)->toBe('ghi789');
    expect($sent->failureReason())->toBeNull();
});

test('queued and scheduled also count as accepted', function () {
    Http::fake([
        'mandrillapp.com/*' => Http::response([[
            'email' => 'reporter@example.com',
            'status' => 'queued',
            '_id' => 'q1',
        ]], 200),
    ]);

    expect(sendTestEmail()->wasAccepted())->toBeTrue();
});

test('a Mandrill API error falls back to the brand SMTP mailbox', function () {
    Http::fake([
        'mandrillapp.com/*' => Http::response([
            'status' => 'error',
            'name' => 'GeneralError',
            'message' => 'Mandrill is down',
        ], 500),
    ]);

    $brand = brandWithSmtpFallback();

    $this->mock(BrandSmtpMailer::class, function ($mock) {
        $mock->shouldReceive('isAvailableFor')->andReturn(true);
        $mock->shouldReceive('sendFor')->once()
            ->andReturn(['success' => true, 'message_id' => '<smtp-1@acme.test>', 'error' => null]);
    });

    $sent = sendTestEmail($brand);

    expect($sent->status)->toBe('sent');
    expect($sent->wasAccepted())->toBeTrue();
    expect($sent->message_id)->toBe('<smtp-1@acme.test>');
    expect($sent->metadata['transport'])->toBe('smtp_fallback');
    expect($sent->metadata['mandrill_error'])->toBe('Mandrill is down');
});

test('a rejected recipient also falls back to the brand SMTP mailbox', function () {
    Http::fake([
        'mandrillapp.com/*' => Http::response([[
            'email' => 'reporter@example.com',
            'status' => 'rejected',
            'reject_reason' => 'unsigned',
            '_id' => 'abc123',
        ]], 200),
    ]);

    $brand = brandWithSmtpFallback();

    $this->mock(BrandSmtpMailer::class, function ($mock) {
        $mock->shouldReceive('isAvailableFor')->andReturn(true);
        $mock->shouldReceive('sendFor')->once()
            ->andReturn(['success' => true, 'message_id' => '<smtp-2@acme.test>', 'error' => null]);
    });

    $sent = sendTestEmail($brand);

    expect($sent->status)->toBe('sent');
    expect($sent->metadata['transport'])->toBe('smtp_fallback');
    expect($sent->metadata['mandrill_error'])->toBe('unsigned');
});

test('when Mandrill and the SMTP fallback both fail, the Mandrill failure is recorded', function () {
    Http::fake([
        'mandrillapp.com/*' => Http::response([
            'status' => 'error',
            'name' => 'GeneralError',
            'message' => 'Mandrill is down',
        ], 500),
    ]);

    $brand = brandWithSmtpFallback();

    $this->mock(BrandSmtpMailer::class, function ($mock) {
        $mock->shouldReceive('isAvailableFor')->andReturn(true);
        $mock->shouldReceive('sendFor')->once()
            ->andReturn(['success' => false, 'message_id' => null, 'error' => 'Connection refused']);
    });

    $sent = sendTestEmail($brand);

    expect($sent->status)->toBe('failed');
    expect($sent->wasAccepted())->toBeFalse();
    expect($sent->failureReason())->toBe('Mandrill is down');
    expect($sent->metadata['smtp_fallback_error'])->toBe('Connection refused');
});

test('with no Mandrill key at all, the brand SMTP mailbox is used directly', function () {
    config(['abusedesk.email.mandrill_key' => null]);

    Http::fake(); // must never be hit

    $brand = brandWithSmtpFallback();

    $this->mock(BrandSmtpMailer::class, function ($mock) {
        $mock->shouldReceive('isAvailableFor')->andReturn(true);
        $mock->shouldReceive('sendFor')->once()
            ->andReturn(['success' => true, 'message_id' => '<smtp-3@acme.test>', 'error' => null]);
    });

    $sent = (new MandrillService)->send(
        toEmail: 'reporter@example.com',
        toName: 'Reporter',
        subject: 'Re: abuse case',
        htmlBody: '<p>hello</p>',
        brand: $brand,
    );

    expect($sent->status)->toBe('sent');
    expect($sent->metadata['transport'])->toBe('smtp_fallback');
    Http::assertNothingSent();
});

test('brand smtp fallback config derives from the inbox connection', function () {
    $brand = Brand::create([
        'name' => 'Acme',
        'slug' => 'acme',
        'from_name' => 'Acme Abuse',
        'from_email' => 'abuse@acme.test',
        'is_active' => true,
    ]);

    // No connection, no explicit config → nothing to fall back to.
    expect($brand->smtpFallbackConfig())->toBeNull();

    EmailConnection::create([
        'name' => 'Acme inbox',
        'brand_id' => $brand->id,
        'protocol' => 'pop3',
        'host' => 'mail.acme.test',
        'port' => 995,
        'username' => 'abuse@acme.test',
        'password' => 'secret',
        'ssl' => true,
        'is_active' => true,
    ]);

    $cfg = $brand->fresh()->smtpFallbackConfig();

    expect($cfg['host'])->toBe('mail.acme.test');
    expect($cfg['port'])->toBe(465);
    expect($cfg['username'])->toBe('abuse@acme.test');
    expect($cfg['password'])->toBe('secret');
    expect($cfg['encryption'])->toBe('ssl');

    // Explicit smtp_* keys override the derived values.
    $brand->update(['smtp_config' => ['smtp_host' => 'smtp.acme.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls']]);

    $cfg = $brand->fresh()->smtpFallbackConfig();

    expect($cfg['host'])->toBe('smtp.acme.test');
    expect($cfg['port'])->toBe(587);
    expect($cfg['encryption'])->toBe('tls');
    expect($cfg['username'])->toBe('abuse@acme.test'); // still from the inbox
});

test('disabling Mandrill routes every send through brand SMTP, even with a valid Mandrill key', function () {
    Storage::fake('local');
    EmailTransportSettings::setMandrillEnabled(false);

    // Mandrill would return a perfectly good "queued" — but it must never
    // be called, because the operator has switched it off.
    Http::fake([
        'mandrillapp.com/*' => Http::response([[
            'email' => 'reporter@example.com',
            'status' => 'queued',
            '_id' => 'should-not-happen',
        ]], 200),
    ]);

    $brand = brandWithSmtpFallback();

    $this->mock(BrandSmtpMailer::class, function ($mock) {
        $mock->shouldReceive('isAvailableFor')->andReturn(true);
        $mock->shouldReceive('sendFor')->once()
            ->andReturn(['success' => true, 'message_id' => '<smtp-off@acme.test>', 'error' => null]);
    });

    $sent = sendTestEmail($brand);

    expect($sent->status)->toBe('sent');
    expect($sent->metadata['transport'])->toBe('smtp_fallback');
    expect($sent->metadata['mandrill_error'])->toBe('Mandrill disabled by operator');
    Http::assertNothingSent();
});

test('with Mandrill disabled and no brand SMTP, canSendFor is false and the send is recorded failed', function () {
    Storage::fake('local');
    EmailTransportSettings::setMandrillEnabled(false);
    Http::fake();

    $brand = Brand::create([
        'name' => 'NoSmtp',
        'slug' => 'nosmtp',
        'from_name' => 'No SMTP',
        'from_email' => 'abuse@nosmtp.test',
        'is_active' => true,
    ]);

    expect(mandrill()->canSendFor($brand))->toBeFalse();

    $sent = sendTestEmail($brand);

    expect($sent->wasAccepted())->toBeFalse();
    expect($sent->status)->toBe('failed');
    Http::assertNothingSent();
});

test('the Mandrill transport switch persists and defaults to enabled', function () {
    Storage::fake('local');

    expect(EmailTransportSettings::mandrillEnabled())->toBeTrue();

    EmailTransportSettings::setMandrillEnabled(false);
    expect(EmailTransportSettings::mandrillEnabled())->toBeFalse();

    EmailTransportSettings::setMandrillEnabled(true);
    expect(EmailTransportSettings::mandrillEnabled())->toBeTrue();
});
