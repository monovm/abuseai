<?php

use App\Models\Brand;
use App\Services\Infrastructure\UrlTakedownService;
use Illuminate\Support\Facades\Http;

function brandWithTakedownConfig(): Brand
{
    return Brand::create([
        'name' => 'URL Brand',
        'slug' => 'url-brand',
        'from_name' => 'Abuse',
        'from_email' => 'abuse@example.com',
        'generic_provider_config' => [
            'url_takedown' => [
                'host_patterns' => ['url1.io'],
                'secrets' => ['token' => 'tok-abc'],
                'lookup' => [
                    'method' => 'GET',
                    'url' => 'https://url1.io/api/urls?short={short}',
                    'headers' => [
                        'Authorization' => 'Bearer {token}',
                        'Content-Type' => 'application/json',
                    ],
                    'id_path' => 'data.urls.0.id',
                ],
                'delete' => [
                    'method' => 'DELETE',
                    'url' => 'https://url1.io/api/url/{id}/delete',
                    'headers' => [
                        'Authorization' => 'Bearer {token}',
                        'Content-Type' => 'application/json',
                    ],
                ],
            ],
        ],
    ]);
}

test('cleanUrl strips defangs and fragment', function () {
    $svc = new UrlTakedownService();
    // hXXp defangs to http (preserves the original scheme).
    expect($svc->cleanUrl('hXXp url1[.]io/rFtSx#phishing@rbc.com'))->toBe('http://url1.io/rFtSx');
    expect($svc->cleanUrl('hXXps url1[.]io/rFtSx'))->toBe('https://url1.io/rFtSx');
    expect($svc->cleanUrl('http://url1[dot]io/rFtSx'))->toBe('http://url1.io/rFtSx');
    expect($svc->cleanUrl('url1.io/rFtSx'))->toBe('https://url1.io/rFtSx');
});

test('extractShort returns first path segment', function () {
    $svc = new UrlTakedownService();
    expect($svc->extractShort('https://url1.io/rFtSx'))->toBe('rFtSx');
    expect($svc->extractShort('https://url1.io/rFtSx/extra'))->toBe('rFtSx');
    expect($svc->extractShort('https://url1.io/'))->toBe('');
});

test('handlesUrl matches host_patterns', function () {
    $svc = new UrlTakedownService();
    $brand = brandWithTakedownConfig();

    expect($svc->handlesUrl($brand, 'https://url1.io/rFtSx'))->toBeTrue();
    expect($svc->handlesUrl($brand, 'https://other.example/rFtSx'))->toBeFalse();
});

test('takedown runs lookup then delete with id substitution', function () {
    Http::fake([
        'https://url1.io/api/urls*' => Http::response([
            'error' => '0',
            'data' => [
                'result' => 1,
                'urls' => [
                    ['id' => 2, 'alias' => 'rFtSx', 'shorturl' => 'https://url1.io/rFtSx'],
                ],
            ],
        ], 200),
        'https://url1.io/api/url/2/delete' => Http::response([
            'error' => 0,
            'message' => 'Link has been deleted successfully',
        ], 200),
    ]);

    $svc = new UrlTakedownService();
    $brand = brandWithTakedownConfig();

    $result = $svc->takedown($brand, 'hXXp url1[.]io/rFtSx#phishing@rbc.com');

    expect($result['success'])->toBeTrue();
    expect($result['id'])->toBe('2');
    expect($result['url'])->toBe('http://url1.io/rFtSx');
    expect($result['message'])->toContain('deleted successfully');

    Http::assertSent(function ($request) {
        // {short} now substitutes the full cleaned URL, so the lookup
        // hits ?short=http://url1.io/rFtSx (not just the alias).
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://url1.io/api/urls?short=')
            && str_contains($request->url(), 'url1.io/rFtSx')
            && $request->header('Authorization') === ['Bearer tok-abc'];
    });
    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_starts_with($request->url(), 'https://url1.io/api/url/2/delete');
    });
});

test('alias placeholder still resolves to the path segment', function () {
    Http::fake([
        'https://url1.io/api/urls*' => Http::response(['data' => ['urls' => [['id' => 7]]]], 200),
        'https://url1.io/api/url/7/delete' => Http::response(['message' => 'ok'], 200),
    ]);

    $brand = brandWithTakedownConfig();
    // Swap the lookup template to use {alias} so we exercise that path.
    $cfg = $brand->generic_provider_config;
    $cfg['url_takedown']['lookup']['url'] = 'https://url1.io/api/urls?alias={alias}';
    $brand->update(['generic_provider_config' => $cfg]);

    $svc = new UrlTakedownService();
    $result = $svc->takedown($brand, 'https://url1.io/gigcW');

    expect($result['success'])->toBeTrue();
    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://url1.io/api/urls?alias=gigcW';
    });
});

test('takedown bails when host is not handled by brand', function () {
    Http::fake();
    $svc = new UrlTakedownService();
    $brand = brandWithTakedownConfig();

    $result = $svc->takedown($brand, 'https://other.example/rFtSx');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('not handled');
    Http::assertNothingSent();
});

test('takedown reports a missing id when lookup body lacks id_path', function () {
    Http::fake([
        'https://url1.io/api/urls*' => Http::response([
            'error' => '0',
            'data' => ['result' => 0, 'urls' => []],
        ], 200),
    ]);

    $svc = new UrlTakedownService();
    $brand = brandWithTakedownConfig();

    $result = $svc->takedown($brand, 'https://url1.io/missing');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('no id was found');
});
