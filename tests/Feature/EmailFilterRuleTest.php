<?php

use App\Models\Brand;
use App\Models\EmailFilterRule;

function makeBrand(): Brand
{
    static $i = 0;
    $i++;
    return Brand::create([
        'name' => "Test Brand {$i}",
        'slug' => "test-brand-{$i}",
        'from_name' => 'Abuse Desk',
        'from_email' => "abuse{$i}@example.com",
    ]);
}

test('global rule matches when pattern in subject', function () {
    EmailFilterRule::create([
        'pattern' => 'newsletter',
        'match_field' => 'any',
        'is_active' => true,
    ]);

    $hit = EmailFilterRule::matchDrop('Weekly Newsletter — May edition', 'Hi all', null);

    expect($hit)->not->toBeNull();
    expect($hit->pattern)->toBe('newsletter');
});

test('rule restricted to subject does not match body hits', function () {
    EmailFilterRule::create([
        'pattern' => 'unsubscribe',
        'match_field' => 'subject',
        'is_active' => true,
    ]);

    $hit = EmailFilterRule::matchDrop('Re: abuse report', 'Click here to unsubscribe', null);

    expect($hit)->toBeNull();
});

test('disabled rule is ignored', function () {
    EmailFilterRule::create([
        'pattern' => 'promotional',
        'match_field' => 'any',
        'is_active' => false,
    ]);

    $hit = EmailFilterRule::matchDrop('Promotional offer', '', null);

    expect($hit)->toBeNull();
});

test('brand-scoped rule only matches its brand', function () {
    $brandA = makeBrand();
    $brandB = makeBrand();

    EmailFilterRule::create([
        'brand_id' => $brandA->id,
        'pattern' => 'survey',
        'match_field' => 'any',
        'is_active' => true,
    ]);

    expect(EmailFilterRule::matchDrop('customer survey', '', $brandA->id))->not->toBeNull();
    expect(EmailFilterRule::matchDrop('customer survey', '', $brandB->id))->toBeNull();
});

test('global rules apply across all brands', function () {
    $brand = makeBrand();

    EmailFilterRule::create([
        'pattern' => 'spam test',
        'match_field' => 'body',
        'is_active' => true,
    ]);

    expect(EmailFilterRule::matchDrop('hi', 'this is a SPAM TEST email', $brand->id))->not->toBeNull();
});
