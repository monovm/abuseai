<?php

use App\Models\IpAddress;
use App\Services\IpInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('expandCidr expands /30 to 2 usable IPs', function () {
    $service = new IpInventoryService();
    $ips = $service->expandCidr('192.168.1.0/30');

    expect($ips)->toHaveCount(2);
    expect($ips)->toContain('192.168.1.1');
    expect($ips)->toContain('192.168.1.2');
    // network (192.168.1.0) and broadcast (192.168.1.3) excluded
});

test('expandCidr expands /32 to 1 IP', function () {
    $service = new IpInventoryService();
    $ips = $service->expandCidr('10.0.0.5/32');

    expect($ips)->toHaveCount(1);
    expect($ips[0])->toBe('10.0.0.5');
});

test('expandCidr expands /24 to 254 usable IPs', function () {
    $service = new IpInventoryService();
    $ips = $service->expandCidr('10.0.1.0/24');

    expect($ips)->toHaveCount(254);
    expect($ips[0])->toBe('10.0.1.1');
    expect($ips[253])->toBe('10.0.1.254');
});

test('expandCidr rejects prefix smaller than /16', function () {
    $service = new IpInventoryService();

    expect($service->expandCidr('10.0.0.0/15'))->toBe([]);
    expect($service->expandCidr('10.0.0.0/8'))->toBe([]);
});

test('expandCidr rejects invalid input', function () {
    $service = new IpInventoryService();

    expect($service->expandCidr('not-an-ip/24'))->toBe([]);
    expect($service->expandCidr('192.168.1.0'))->toBe([]); // no prefix
});

test('expandDashRange expands correctly', function () {
    $service = new IpInventoryService();
    $ips = $service->expandDashRange('10.0.0.1-10.0.0.5');

    expect($ips)->toHaveCount(5);
    expect($ips[0])->toBe('10.0.0.1');
    expect($ips[4])->toBe('10.0.0.5');
});

test('expandDashRange rejects reversed range', function () {
    $service = new IpInventoryService();

    expect($service->expandDashRange('10.0.0.5-10.0.0.1'))->toBe([]);
});

test('expandDashRange rejects range larger than 65536', function () {
    $service = new IpInventoryService();

    expect($service->expandDashRange('10.0.0.1-10.2.0.0'))->toBe([]);
});

test('addSingle creates an IP record', function () {
    $service = new IpInventoryService();
    $ip = $service->addSingle('192.168.1.100', 'web-01');

    expect($ip->ip_address)->toBe('192.168.1.100');
    expect($ip->server_name)->toBe('web-01');
    expect($ip->status)->toBe('active');
    expect(IpAddress::count())->toBe(1);
});

test('addSingle updates existing IP on duplicate', function () {
    $service = new IpInventoryService();
    $service->addSingle('192.168.1.100', 'web-01');
    $service->addSingle('192.168.1.100', 'web-02');

    expect(IpAddress::count())->toBe(1);
    expect(IpAddress::first()->server_name)->toBe('web-02');
});

test('addRange imports all IPs from CIDR', function () {
    $service = new IpInventoryService();
    $count = $service->addRange('10.0.0.0/28', 'subnet-a');

    // /28 = 14 usable hosts
    expect($count)->toBe(14);
    expect(IpAddress::count())->toBe(14);
    expect(IpAddress::first()->range_source)->toBe('10.0.0.0/28');
    expect(IpAddress::first()->server_name)->toBe('subnet-a');
});

test('addDashRange imports all IPs from range', function () {
    $service = new IpInventoryService();
    $count = $service->addDashRange('172.16.0.1-172.16.0.10', 'rack-b');

    expect($count)->toBe(10);
    expect(IpAddress::count())->toBe(10);
});

test('import auto-detects single IP', function () {
    $service = new IpInventoryService();
    $result = $service->import('8.8.8.8', 'dns-01');

    expect($result['type'])->toBe('single');
    expect($result['count'])->toBe(1);
    expect(IpAddress::count())->toBe(1);
});

test('import auto-detects CIDR', function () {
    $service = new IpInventoryService();
    $result = $service->import('10.10.0.0/30');

    // Service returns "cidr_expanded" when the CIDR is small enough to
    // expand into individual rows; "block" for larger ones.
    expect($result['type'])->toBe('cidr_expanded');
    expect($result['count'])->toBe(2);
});

test('import auto-detects dash range', function () {
    $service = new IpInventoryService();
    $result = $service->import('10.10.0.1-10.10.0.3');

    expect($result['type'])->toBe('range');
    expect($result['count'])->toBe(3);
});

test('import returns invalid for bad input', function () {
    $service = new IpInventoryService();
    $result = $service->import('hello_world');

    expect($result['type'])->toBe('invalid');
    expect($result['count'])->toBe(0);
});

test('bulkImport handles mixed input', function () {
    $service = new IpInventoryService();
    $results = $service->bulkImport("# Comment line
192.168.0.1,server-a
192.168.0.2,server-b
10.0.0.0/30
");

    expect($results['total'])->toBe(3);
    expect($results['imported'])->toBe(4); // 1 + 1 + 2
    expect($results['skipped'])->toBe(0);
});

test('isOurIp returns true for active IP in inventory', function () {
    $service = new IpInventoryService();
    $service->addSingle('5.5.5.5');

    expect($service->isOurIp('5.5.5.5'))->toBeTrue();
    expect($service->isOurIp('6.6.6.6'))->toBeFalse();
});

test('isOurIp returns false for decommissioned IP', function () {
    $service = new IpInventoryService();
    $ip = $service->addSingle('7.7.7.7');
    $ip->update(['status' => 'decommissioned']);

    expect($service->isOurIp('7.7.7.7'))->toBeFalse();
});

test('addSingle with tags stores tags correctly', function () {
    $service = new IpInventoryService();
    $ip = $service->addSingle('9.9.9.9', 'dns', null, ['datacenter-1', 'rack-A']);

    expect($ip->tags)->toBe(['datacenter-1', 'rack-A']);
});
