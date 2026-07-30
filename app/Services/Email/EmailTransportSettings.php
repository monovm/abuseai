<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Storage;

/**
 * Operator-controlled email transport switch.
 *
 * The automatic Mandrill → brand-SMTP fallback only triggers when Mandrill
 * *rejects* a message. A broken or suspended Mandrill account still returns
 * "queued" (an accepted status), so those messages look sent but never
 * arrive — and the fallback never fires. This switch lets an operator say
 * "Mandrill is down, route everything through the brands' own inboxes"
 * without editing .env or removing the API key.
 *
 * Persisted to a JSON file under storage/app (not the cache) so it survives
 * `cache:clear` / `optimize:clear`, mirroring AiProviderFactory.
 */
class EmailTransportSettings
{
    private const SETTINGS_FILE = 'email_transport_settings.json';

    /**
     * Whether Mandrill may be used at all. When false, every send() skips
     * Mandrill and goes straight to the brand's SMTP inbox. Defaults to
     * true so nothing changes until an operator flips it.
     */
    public static function mandrillEnabled(): bool
    {
        return (bool) (static::read()['mandrill_enabled'] ?? true);
    }

    public static function setMandrillEnabled(bool $enabled): void
    {
        $settings = static::read();
        $settings['mandrill_enabled'] = $enabled;
        static::write($settings);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function read(): array
    {
        try {
            if (! Storage::disk('local')->exists(self::SETTINGS_FILE)) {
                return [];
            }
            $data = json_decode(Storage::disk('local')->get(self::SETTINGS_FILE), true);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    protected static function write(array $settings): void
    {
        Storage::disk('local')->put(
            self::SETTINGS_FILE,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
