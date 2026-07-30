<?php

namespace App\Services\Infrastructure;

use App\Models\Brand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Two-step URL takedown against a brand's Custom HTTP API. Used when a case
 * targets a malicious URL hosted by a service the brand owns (e.g. their
 * own URL shortener): look the URL up by its short alias, get back an ID,
 * then DELETE that ID.
 *
 * Config lives at $brand->generic_provider_config.url_takedown:
 * {
 *   "host_patterns": ["url1.io"],          // hosts this config can act on
 *   "secrets":       { "token": "..." },   // merged into placeholders
 *   "lookup": {
 *     "method":  "GET",
 *     "url":     "https://url1.io/api/urls?short={short}",
 *     "headers": { "Authorization": "Bearer {token}", "Content-Type": "application/json" },
 *     "id_path": "data.urls.0.id"
 *   },
 *   "delete": {
 *     "method":  "DELETE",
 *     "url":     "https://url1.io/api/url/{id}/delete",
 *     "headers": { "Authorization": "Bearer {token}", "Content-Type": "application/json" }
 *   }
 * }
 *
 * Placeholders supported in url / headers / body:
 *   {short}  — the short alias parsed from the input URL (path segment,
 *              e.g. "gigcW" for https://url1.io/gigcW)
 *   {url}    — the cleaned URL itself (no defangs, no fragment)
 *   {host}   — the URL's host
 *   {id}     — set after lookup, available in the delete step
 *   {<secret>} — any key under config.secrets
 */
class UrlTakedownService
{
    public function isConfigured(Brand $brand): bool
    {
        $cfg = $brand->generic_provider_config['url_takedown'] ?? null;
        return is_array($cfg)
            && ! empty($cfg['lookup']['url'])
            && ! empty($cfg['delete']['url']);
    }

    /**
     * Does this brand's takedown config accept the given URL? Matches the
     * URL host against host_patterns (case-insensitive substring). Empty
     * host_patterns means "any host" — useful when a brand only ever sees
     * one upstream.
     */
    public function handlesUrl(Brand $brand, string $url): bool
    {
        if (! $this->isConfigured($brand)) {
            return false;
        }
        $host = $this->parseHost($url);
        if ($host === null) {
            return false;
        }
        $patterns = (array) ($brand->generic_provider_config['url_takedown']['host_patterns'] ?? []);
        if (empty($patterns)) {
            return true;
        }
        $hostLower = strtolower($host);
        foreach ($patterns as $p) {
            $p = strtolower(trim((string) $p));
            if ($p !== '' && str_contains($hostLower, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run the lookup → delete sequence. Returns a result array with success
     * flag, the resolved id (if any), the deleted URL, and either a
     * human-readable message or an error string.
     *
     * @return array{success: bool, id?: string, url: string, message?: string, error?: string, lookup_body?: array, delete_body?: array}
     */
    public function takedown(Brand $brand, string $rawUrl): array
    {
        $url = $this->cleanUrl($rawUrl);
        if ($url === '') {
            return ['success' => false, 'url' => $rawUrl, 'error' => 'Empty URL.'];
        }

        if (! $this->isConfigured($brand)) {
            return ['success' => false, 'url' => $url, 'error' => 'Brand has no url_takedown config.'];
        }
        if (! $this->handlesUrl($brand, $url)) {
            return ['success' => false, 'url' => $url, 'error' => 'URL host is not handled by this brand.'];
        }

        $cfg = $brand->generic_provider_config['url_takedown'];
        $alias = $this->extractShort($url);
        if ($alias === '') {
            return ['success' => false, 'url' => $url, 'error' => 'Could not parse short alias from URL.'];
        }

        $secrets = is_array($cfg['secrets'] ?? null) ? $cfg['secrets'] : [];
        $vars = array_merge($secrets, [
            'short' => $url,
            'alias' => $alias,
            'url' => $url,
            'host' => (string) $this->parseHost($url),
        ]);

        $lookupBody = $this->request($cfg['lookup'], $vars);
        if ($lookupBody === null) {
            return ['success' => false, 'url' => $url, 'error' => 'Lookup request failed.'];
        }

        $idPath = (string) ($cfg['lookup']['id_path'] ?? 'data.id');
        $id = $this->extract($lookupBody, $idPath);
        if ($id === null || $id === '') {
            return [
                'success' => false,
                'url' => $url,
                'error' => 'Lookup succeeded but no id was found at path ' . $idPath . '.',
                'lookup_body' => $lookupBody,
            ];
        }

        $deleteVars = $vars + ['id' => (string) $id];
        $deleteBody = $this->request($cfg['delete'], $deleteVars);
        if ($deleteBody === null) {
            return [
                'success' => false,
                'url' => $url,
                'id' => (string) $id,
                'error' => 'Delete request failed.',
                'lookup_body' => $lookupBody,
            ];
        }

        return [
            'success' => true,
            'id' => (string) $id,
            'url' => $url,
            'message' => is_string($deleteBody['message'] ?? null) ? $deleteBody['message'] : 'URL deleted.',
            'lookup_body' => $lookupBody,
            'delete_body' => $deleteBody,
        ];
    }

    /**
     * Drop defangs ("hXXp", "[.]"), strip the fragment, and lowercase the
     * scheme/host so the regex-based parsers later don't trip on the
     * common forms that appear inside abuse-report email bodies.
     */
    public function cleanUrl(string $url): string
    {
        $url = trim($url);
        // "hXXp" / "hXXps" → "http" / "https". Word-bounded so we don't
        // mangle a legitimate substring that happens to contain "hxxp".
        $url = preg_replace('/\bhxxps\b/i', 'https', $url) ?? $url;
        $url = preg_replace('/\bhxxp\b/i', 'http', $url) ?? $url;
        $url = str_replace(['[.]', '(.)', '[dot]'], '.', $url);
        // Drop the URL fragment (#phishing@rbc.com etc.). It's never part
        // of the short-alias path and would otherwise confuse extractShort.
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        // Repair defanged scheme separators — "http " / "http[:]//" /
        // "http :// " all map back to "http://". Single replacement so we
        // don't accidentally eat a slash deeper in the path.
        $url = preg_replace('#\b(https?)[\s:/\\\\\[\]]+#i', '$1://', $url, 1) ?? $url;
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return $url;
    }

    protected function parseHost(string $url): ?string
    {
        $host = parse_url($this->cleanUrl($url), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Pull the short alias out of a URL. For "https://url1.io/rFtSx" we want
     * "rFtSx" — the first non-empty path segment. Falls back to empty
     * string when there's no path.
     */
    public function extractShort(string $url): string
    {
        $path = parse_url($this->cleanUrl($url), PHP_URL_PATH) ?? '';
        $path = trim($path, "/ \t");
        if ($path === '') {
            return '';
        }
        $segment = explode('/', $path)[0] ?? '';
        return trim($segment);
    }

    /**
     * Render and execute one HTTP step. Mirrors GenericHttpProvider's
     * placeholder substitution + JSON decoding so behaviour is consistent
     * across the two custom-HTTP entry points.
     */
    protected function request(array $spec, array $vars): ?array
    {
        $method = strtoupper($spec['method'] ?? 'GET');
        $url = $this->renderTemplate((string) $spec['url'], $vars);
        $headers = [];
        foreach (($spec['headers'] ?? []) as $k => $v) {
            $headers[$k] = $this->renderTemplate((string) $v, $vars);
        }

        $body = null;
        if (isset($spec['body'])) {
            $body = is_string($spec['body'])
                ? $this->renderTemplate($spec['body'], $vars)
                : $this->renderArrayTemplates((array) $spec['body'], $vars);
        }

        try {
            $client = Http::withHeaders($headers)->timeout(30);
            $response = match ($method) {
                'GET' => $client->get($url),
                'POST' => $client->asJson()->post($url, is_array($body) ? $body : []),
                'PUT' => $client->asJson()->put($url, is_array($body) ? $body : []),
                'DELETE' => $client->asJson()->delete($url, is_array($body) ? $body : []),
                default => $client->asJson()->send($method, $url, ['json' => $body]),
            };

            if (! $response->successful()) {
                Log::warning('UrlTakedownService: non-2xx response', [
                    'method' => $method, 'url' => $url, 'status' => $response->status(),
                ]);
                return null;
            }
            $decoded = $response->json();
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::error('UrlTakedownService: request failed', [
                'method' => $method, 'url' => $url, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function renderTemplate(string $template, array $vars): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
        }, $template) ?? $template;
    }

    protected function renderArrayTemplates(array $template, array $vars): array
    {
        foreach ($template as $k => $v) {
            if (is_string($v)) {
                $template[$k] = $this->renderTemplate($v, $vars);
            } elseif (is_array($v)) {
                $template[$k] = $this->renderArrayTemplates($v, $vars);
            }
        }
        return $template;
    }

    protected function extract(?array $body, string $path): mixed
    {
        if ($body === null || $path === '') {
            return null;
        }
        $cursor = $body;
        foreach (explode('.', $path) as $part) {
            if (is_array($cursor) && array_key_exists($part, $cursor)) {
                $cursor = $cursor[$part];
            } elseif (is_array($cursor) && ctype_digit($part) && array_key_exists((int) $part, $cursor)) {
                $cursor = $cursor[(int) $part];
            } else {
                return null;
            }
        }
        return $cursor;
    }
}
