<?php

namespace App\Enums;

enum SeverityLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 60 => self::Critical,
            $score >= 40 => self::High,
            $score >= 21 => self::Medium,
            default => self::Low,
        };
    }
}
