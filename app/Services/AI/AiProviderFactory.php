<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AiProviderFactory
{
    /**
     * Where the active-provider choice is persisted on disk. Stored outside
     * the cache layer because every `php artisan cache:clear` (and the
     * larger `optimize:clear` that wraps it) blew away the previous in-cache
     * value and silently reverted operators back to the Claude default.
     * A tiny JSON file under storage/app survives all the cache flushes.
     */
    private const SETTINGS_FILE = 'ai_provider_settings.json';

    /**
     * Create the currently active AI provider.
     */
    public static function make(?string $provider = null): AiProviderInterface
    {
        $provider = $provider ?? static::getActiveProvider();

        return match ($provider) {
            'openai' => new OpenAiProvider(),
            default => new ClaudeProvider(),
        };
    }

    /**
     * Get the currently active provider name.
     *
     * Order of preference (first non-empty wins):
     *   1. The persisted JSON file (set via /admin/ai-settings)
     *   2. The legacy in-cache key (so existing prod state isn't lost on
     *      first deploy of this change — a one-time read until the next save)
     *   3. config('ai.active_provider')
     *   4. 'claude' as a final fallback
     */
    public static function getActiveProvider(): string
    {
        $persisted = static::readSettings()['active_provider'] ?? null;
        if ($persisted) {
            return $persisted;
        }

        $cached = Cache::get('ai_provider');
        if ($cached) {
            return $cached;
        }

        return config('ai.active_provider', 'claude');
    }

    /**
     * Set the active provider (persists to disk).
     */
    public static function setActiveProvider(string $provider): void
    {
        if (! in_array($provider, ['claude', 'openai'])) {
            throw new \InvalidArgumentException("Unknown AI provider: {$provider}");
        }

        $settings = static::readSettings();
        $settings['active_provider'] = $provider;
        static::writeSettings($settings);

        // Keep the cache in sync so any code path that still reads from the
        // cache (and any external scripts) sees the new value too.
        Cache::forever('ai_provider', $provider);
    }

    /**
     * Get available providers with their config status.
     */
    public static function availableProviders(): array
    {
        return [
            'claude' => [
                'name' => 'Anthropic Claude',
                'model' => Cache::get('ai_claude_model', config('ai.providers.claude.model', 'claude-sonnet-5')),
                'configured' => ! empty(config('ai.providers.claude.api_key')),
            ],
            'openai' => [
                'name' => 'OpenAI',
                'model' => Cache::get('ai_openai_model', config('ai.providers.openai.model', 'gpt-4o')),
                'configured' => ! empty(config('ai.providers.openai.api_key')),
            ],
        ];
    }

    /**
     * Read the on-disk settings, returning an empty array if the file
     * doesn't exist yet or is malformed (don't blow up the app over a
     * corrupt settings file — we'd rather fall through to config).
     *
     * @return array<string, mixed>
     */
    protected static function readSettings(): array
    {
        try {
            if (! Storage::disk('local')->exists(self::SETTINGS_FILE)) {
                return [];
            }
            $raw = Storage::disk('local')->get(self::SETTINGS_FILE);
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    protected static function writeSettings(array $settings): void
    {
        Storage::disk('local')->put(
            self::SETTINGS_FILE,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
