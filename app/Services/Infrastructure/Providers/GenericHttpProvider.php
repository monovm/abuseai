<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talk to a brand's homegrown panel / billing system without writing a
 * dedicated provider class. Driven entirely by the JSON config stored on
 * the brand row at $brand->generic_provider_config.
 *
 * Config shape (everything optional except `lookup`):
 * {
 *   "lookup": {
 *     "method": "GET",
 *     "url": "https://billing.example.com/api/v1/services?ip={ip}",
 *     "headers": { "Authorization": "Bearer {token}" },
 *     "secrets": { "token": "..." },        // merged into placeholders
 *     "map": {
 *       "service_id":  "data.0.id",
 *       "client_id":   "data.0.customer.id",
 *       "hostname":    "data.0.hostname",
 *       "status":      "data.0.state",
 *       "registered_at": "data.0.created_at",
 *       "user_email":  "data.0.customer.email",
 *       "product":     "data.0.product"
 *     },
 *     "status_map": { "online": "active", "offline": "suspended" }
 *   },
 *   "actions": {
 *     "suspend":   { "method": "POST", "url": "https://.../services/{service_id}/suspend",
 *                    "body": { "reason": "{reason}" } },
 *     "unsuspend": { "method": "POST", "url": "https://.../services/{service_id}/unsuspend" }
 *   },
 *   "admin_url": "https://billing.example.com/admin/services/{service_id}"
 * }
 *
 * Placeholders supported in URL / headers / body:
 *   {ip}            request IP
 *   {service_id}    set after lookup; available in action requests
 *   {client_id}     same
 *   {reason}        passed into suspend()
 *   {<secret>}      from config.lookup.secrets
 *
 * Mapping is simple dot-path with optional numeric indices — no JSONPath
 * runtime needed. If a brand needs filtering or arrays-of-arrays, write
 * a real provider class instead; this is the 80% case.
 */
class GenericHttpProvider implements InfrastructureProvider
{
    public function type(): ProviderType
    {
        return ProviderType::GenericHttp;
    }

    public function name(): string
    {
        return 'generic_http';
    }

    public function isConfigured(Brand $brand): bool
    {
        $cfg = $brand->generic_provider_config ?? [];
        return ! empty($cfg['lookup']['url']);
    }

    public function capabilities(Brand $brand): array
    {
        if (! $this->isConfigured($brand)) {
            return [];
        }
        $cfg = $brand->generic_provider_config ?? [];
        $caps = [ProviderCapability::Lookup];
        if (! empty($cfg['actions']['suspend']['url'])) {
            $caps[] = ProviderCapability::Suspend;
        }
        if (! empty($cfg['actions']['unsuspend']['url'])) {
            $caps[] = ProviderCapability::Unsuspend;
        }
        return $caps;
    }

    public function lookupByIp(Brand $brand, string $ip): ?ServiceRecord
    {
        $cfg = $brand->generic_provider_config ?? [];
        $lookup = $cfg['lookup'] ?? [];
        if (empty($lookup['url'])) {
            return null;
        }

        $secrets = is_array($lookup['secrets'] ?? null) ? $lookup['secrets'] : [];
        $vars = array_merge($secrets, ['ip' => $ip]);

        $body = $this->renderHttp($lookup, $vars);
        if (! is_array($body)) {
            return null;
        }

        $map = is_array($lookup['map'] ?? null) ? $lookup['map'] : [];
        $statusMap = is_array($lookup['status_map'] ?? null) ? $lookup['status_map'] : [];

        $serviceId = $this->extract($body, $map['service_id'] ?? null);
        if ($serviceId === null || $serviceId === '') {
            return null;
        }

        $rawStatus = (string) ($this->extract($body, $map['status'] ?? null) ?? '');
        $status = strtolower($statusMap[$rawStatus] ?? $rawStatus);
        if ($status === '') {
            $status = 'unknown';
        }

        $registeredAt = null;
        $regRaw = $this->extract($body, $map['registered_at'] ?? null);
        if ($regRaw) {
            try {
                $registeredAt = Carbon::parse((string) $regRaw);
            } catch (\Throwable) {
            }
        }

        $adminUrl = null;
        if (! empty($cfg['admin_url'])) {
            $adminUrl = $this->renderTemplate($cfg['admin_url'], array_merge($vars, [
                'service_id' => (string) $serviceId,
                'client_id' => (string) ($this->extract($body, $map['client_id'] ?? null) ?? ''),
            ]));
        }

        $rawFields = [];
        foreach ($map as $field => $path) {
            if (in_array($field, ['service_id', 'client_id', 'status', 'registered_at', 'user_email', 'hostname', 'product'], true)) {
                continue;
            }
            $val = $this->extract($body, $path);
            if ($val !== null && $val !== '') {
                $rawFields[$field] = is_scalar($val) ? (string) $val : json_encode($val);
            }
        }

        return new ServiceRecord(
            providerType: $this->type()->value,
            serviceId: (string) $serviceId,
            clientId: ($cid = $this->extract($body, $map['client_id'] ?? null)) !== null ? (string) $cid : null,
            hostname: ($h = $this->extract($body, $map['hostname'] ?? null)) !== null ? (string) $h : null,
            ip: $ip,
            product: ($p = $this->extract($body, $map['product'] ?? null)) !== null ? (string) $p : null,
            status: $status,
            statusLabel: $rawStatus !== '' ? $rawStatus : null,
            userEmail: ($e = $this->extract($body, $map['user_email'] ?? null)) !== null ? (string) $e : null,
            registeredAt: $registeredAt,
            adminUrl: $adminUrl,
            capabilities: $this->capabilities($brand),
            rawFields: $rawFields,
            rawProvider: $this->name(),
        );
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        return $this->runAction($brand, 'suspend', $service, ['reason' => $reason]);
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        return $this->runAction($brand, 'unsuspend', $service);
    }

    protected function runAction(Brand $brand, string $action, ServiceRecord $service, array $extra = []): ProviderActionResult
    {
        $cfg = $brand->generic_provider_config ?? [];
        $spec = $cfg['actions'][$action] ?? null;
        if (! $spec || empty($spec['url'])) {
            return ProviderActionResult::fail("'{$action}' is not configured for this brand.");
        }

        $secrets = is_array($cfg['lookup']['secrets'] ?? null) ? $cfg['lookup']['secrets'] : [];
        $vars = array_merge($secrets, $extra, [
            'service_id' => $service->serviceId,
            'client_id' => $service->clientId ?? '',
            'ip' => $service->ip ?? '',
        ]);

        $resp = $this->renderHttp($spec, $vars);
        if ($resp === null) {
            return ProviderActionResult::fail("'{$action}' request failed (network error).");
        }
        // Generic HTTP can't read the remote system's success conventions
        // — declare success on any 2xx and surface the body for debugging.
        return ProviderActionResult::ok("'{$action}' succeeded.", is_array($resp) ? $resp : []);
    }

    /**
     * Run the configured HTTP request and decode JSON. Returns the decoded
     * body (or null on transport failure / non-2xx). Centralised so action
     * and lookup share auth/template handling.
     */
    protected function renderHttp(array $spec, array $vars): ?array
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
                Log::warning('GenericHttpProvider: non-2xx response', [
                    'method' => $method, 'url' => $url, 'status' => $response->status(),
                ]);
                return null;
            }

            $decoded = $response->json();
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::error('GenericHttpProvider: request failed', [
                'method' => $method, 'url' => $url, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** Replace {placeholder} tokens in a string. Unknown placeholders are left in. */
    protected function renderTemplate(string $template, array $vars): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($vars) {
            $key = $m[1];
            return array_key_exists($key, $vars) ? (string) $vars[$key] : $m[0];
        }, $template) ?? $template;
    }

    /** Recursively walk an array template, replacing string placeholders. */
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

    /**
     * Pull a value out of a nested array using a dot-path. Numeric
     * components are array indices, everything else is a key. Returns
     * null on miss — never throws.
     *
     * Examples:
     *   "data.0.id"     → $body['data'][0]['id']
     *   "customer.email" → $body['customer']['email']
     */
    protected function extract(?array $body, ?string $path): mixed
    {
        if ($body === null || $path === null || $path === '') {
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
