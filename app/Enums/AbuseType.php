<?php

namespace App\Enums;

enum AbuseType: string
{
    case Spam = 'spam';
    case Phishing = 'phishing';
    case Malware = 'malware';
    case Ddos = 'ddos';
    case Csam = 'csam';
    case Copyright = 'copyright';
    case Fraud = 'fraud';
    case LawEnforcement = 'law_enforcement';
    case BruteForce = 'brute_force';
    case Intrusion = 'intrusion';
    case Botnet = 'botnet';
    case Other = 'other';

    public function baseWeight(): int
    {
        return match ($this) {
            self::Csam => 10,
            self::Ddos => 8,
            self::Phishing => 8,
            self::LawEnforcement => 8,
            self::Malware => 7,
            self::Botnet => 7,
            self::Fraud => 6,
            self::Intrusion => 6,
            self::Spam => 3,
            self::BruteForce => 3,
            self::Copyright => 2,
            self::Other => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam',
            self::Phishing => 'Phishing',
            self::Malware => 'Malware',
            self::Ddos => 'DDoS',
            self::Csam => 'CSAM',
            self::Copyright => 'Copyright / DMCA',
            self::Fraud => 'Fraud / Scam',
            self::LawEnforcement => 'Law Enforcement Request',
            self::BruteForce => 'Brute Force / Scanning',
            self::Intrusion => 'Intrusion / Hacking',
            self::Botnet => 'Botnet / C2',
            self::Other => 'Other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Spam => '📧',
            self::Phishing => '🎣',
            self::Malware => '🦠',
            self::Ddos => '💥',
            self::Csam => '🚨',
            self::Copyright => '©️',
            self::Fraud => '💰',
            self::LawEnforcement => '⚖️',
            self::BruteForce => '🔓',
            self::Intrusion => '🔐',
            self::Botnet => '🤖',
            self::Other => '📋',
        };
    }
}
