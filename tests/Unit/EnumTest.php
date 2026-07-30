<?php

use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Enums\ActionType;

test('AbuseType has correct base weights', function () {
    expect(AbuseType::Csam->baseWeight())->toBe(10);
    expect(AbuseType::Ddos->baseWeight())->toBe(8);
    expect(AbuseType::Phishing->baseWeight())->toBe(8);
    expect(AbuseType::LawEnforcement->baseWeight())->toBe(8);
    expect(AbuseType::Malware->baseWeight())->toBe(7);
    expect(AbuseType::Botnet->baseWeight())->toBe(7);
    expect(AbuseType::Fraud->baseWeight())->toBe(6);
    expect(AbuseType::Intrusion->baseWeight())->toBe(6);
    expect(AbuseType::Spam->baseWeight())->toBe(3);
    expect(AbuseType::BruteForce->baseWeight())->toBe(3);
    expect(AbuseType::Copyright->baseWeight())->toBe(2);
    expect(AbuseType::Other->baseWeight())->toBe(1);
});

test('SeverityLevel::fromScore returns correct levels', function () {
    // Thresholds: <21 Low, 21-39 Medium, 40-59 High, >=60 Critical
    expect(SeverityLevel::fromScore(0))->toBe(SeverityLevel::Low);
    expect(SeverityLevel::fromScore(15))->toBe(SeverityLevel::Low);
    expect(SeverityLevel::fromScore(20))->toBe(SeverityLevel::Low);
    expect(SeverityLevel::fromScore(21))->toBe(SeverityLevel::Medium);
    expect(SeverityLevel::fromScore(39))->toBe(SeverityLevel::Medium);
    expect(SeverityLevel::fromScore(40))->toBe(SeverityLevel::High);
    expect(SeverityLevel::fromScore(59))->toBe(SeverityLevel::High);
    expect(SeverityLevel::fromScore(60))->toBe(SeverityLevel::Critical);
    expect(SeverityLevel::fromScore(100))->toBe(SeverityLevel::Critical);
});

test('all enums have expected case count', function () {
    expect(AbuseType::cases())->toHaveCount(12);
    expect(CaseStatus::cases())->toHaveCount(5);
    expect(SeverityLevel::cases())->toHaveCount(4);
    expect(ActionType::cases())->toHaveCount(15);
});
