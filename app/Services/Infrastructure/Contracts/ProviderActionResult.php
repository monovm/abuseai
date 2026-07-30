<?php

namespace App\Services\Infrastructure\Contracts;

/**
 * Outcome of a provider action (suspend / unsuspend / etc.). Providers
 * always return one of these — never throw — so the caller can flash
 * a deterministic message regardless of which integration is active.
 */
class ProviderActionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $raw = [],
    ) {}

    public static function ok(string $message, array $raw = []): self
    {
        return new self(true, $message, $raw);
    }

    public static function fail(string $message, array $raw = []): self
    {
        return new self(false, $message, $raw);
    }
}
