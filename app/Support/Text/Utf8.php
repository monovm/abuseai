<?php

namespace App\Support\Text;

class Utf8
{
    /**
     * Coerce a string to valid UTF-8, dropping byte sequences that are
     * not. Abuse-report content arrives in many charsets — a
     * Windows-1250 Polish .eml, a Latin-1 forwarded notice — and those
     * raw bytes survive into the report text. Left unconverted they
     * make json_encode() fail ("Malformed UTF-8 characters"), which
     * silently aborts the whole AI request before it ever leaves the
     * app, and can also break DB writes and JSON API responses.
     */
    public static function clean(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Drop invalid byte sequences. iconv //IGNORE is the cleanest;
        // fall back to an mb round-trip if iconv is unavailable.
        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (! is_string($cleaned)) {
            $cleaned = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return is_string($cleaned) ? $cleaned : '';
    }

    /**
     * Recursively coerce every string inside an array (keys and values) to
     * valid UTF-8. Used at boundaries that json_encode() the whole
     * structure — most importantly Livewire's component-state hydration,
     * where a single malformed byte anywhere in a public property throws
     * "Malformed UTF-8 characters" and 500s the entire page. Non-string
     * scalars pass through untouched.
     */
    public static function cleanDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return static::clean($value);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? static::clean($k) : $k;
                $out[$key] = static::cleanDeep($v);
            }

            return $out;
        }

        return $value;
    }
}
