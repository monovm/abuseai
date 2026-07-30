<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WHMCS API integration.
 * Only two API calls used:
 *   - GetClientsProducts(domain=hostname) → find service + client
 *   - ModuleSuspend(serviceid, suspendreason) → suspend service
 */
class WhmcsService
{
    protected string $apiUrl;
    protected string $apiIdentifier;
    protected string $apiSecret;
    protected ?string $adminUsername;

    public function __construct(?array $config = null)
    {
        if ($config) {
            $this->apiUrl = rtrim($config['url'] ?? '', '/');
            $this->apiIdentifier = $config['api_identifier'] ?? '';
            $this->apiSecret = $config['api_secret'] ?? '';
            $this->adminUsername = $config['admin_username']
                ?? config('abusedesk.whmcs.admin_username');
        } else {
            $this->apiUrl = rtrim(config('abusedesk.whmcs.url', ''), '/');
            $this->apiIdentifier = config('abusedesk.whmcs.api_identifier', '');
            $this->apiSecret = config('abusedesk.whmcs.api_secret', '');
            $this->adminUsername = config('abusedesk.whmcs.admin_username');
        }
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiUrl) && ! empty($this->apiIdentifier) && ! empty($this->apiSecret);
    }

    /**
     * Find service by VPS hostname.
     *
     * Convenience wrapper around findServiceBy('domain', ...) — kept as
     * a named method because the WHMCS+VZ provider's default match path
     * is hostname-to-domain and this reads cleaner than passing a column
     * string at the call site.
     */
    public function findServiceByHostname(string $hostname): ?array
    {
        return $this->findServiceBy('domain', $hostname);
    }

    /**
     * Find service by dedicated IP. Used in whmcs-only mode where there's
     * no Virtualizor pivot.
     *
     * Tries the cheap `domain={ip}` query first (some operators paste
     * the IP into the Domain column), then falls back to a paginated
     * scan filtering on `dedicatedip`. Capped at $maxScan rows so a
     * runaway loop can't DoS WHMCS.
     */
    public function findServiceByIp(string $ip, int $maxScan = 5000): ?array
    {
        // Try the cheap domain-as-IP shortcut first.
        $direct = $this->apiCall('GetClientsProducts', [
            'domain' => $ip,
            'limitnum' => 10,
        ]);
        if ($product = $this->productFromResponse($direct, $ip)) {
            return $product;
        }

        return $this->scanByDedicatedIp($ip, $maxScan);
    }

    /**
     * Generalised lookup. The combined WHMCS+Virtualizor provider uses
     * this to swap match strategies (hostname vs IP source × domain vs
     * dedicatedip target) without each strategy needing its own method.
     *
     * @param string $field  'domain' | 'dedicatedip'
     * @param string $value  what to match against
     */
    public function findServiceBy(string $field, string $value, int $maxScan = 5000): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($field === 'domain') {
            $resp = $this->apiCall('GetClientsProducts', [
                'domain' => $value,
                'limitnum' => 10,
            ]);
            return $this->productFromResponse($resp);
        }

        if ($field === 'dedicatedip') {
            return $this->scanByDedicatedIp($value, $maxScan);
        }

        Log::warning('WhmcsService: unknown match field', ['field' => $field]);
        return null;
    }

    /**
     * Paginate through GetClientsProducts and return the first row whose
     * dedicatedip matches. Used by both whmcs-only IP lookup and the
     * VZ+WHMCS *_to_dedicatedip strategies. O(N) — slow on large WHMCS
     * installs; the UI surfaces a "slow scan" warning when admins pick
     * one of those strategies.
     */
    protected function scanByDedicatedIp(string $value, int $maxScan): ?array
    {
        $pageSize = 200;
        $scanned = 0;
        $start = 0;

        while ($scanned < $maxScan) {
            $resp = $this->apiCall('GetClientsProducts', [
                'limitnum' => $pageSize,
                'limitstart' => $start,
            ]);
            if (! $resp || ($resp['result'] ?? '') !== 'success') {
                return null;
            }

            $products = $resp['products']['product'] ?? [];
            if (empty($products)) {
                return null;
            }

            foreach ($products as $product) {
                $rowIp = trim((string) ($product['dedicatedip'] ?? ''));
                if ($rowIp !== '' && $rowIp === $value) {
                    return $this->shapeProduct($product);
                }
            }

            $scanned += count($products);
            if (count($products) < $pageSize) {
                return null;
            }
            $start += $pageSize;
        }

        return null;
    }

    /**
     * Pull the first product out of a GetClientsProducts response and shape
     * it. If $expectIp is given, also confirm the row matches — protects
     * against installs where two services share a hostname/domain.
     */
    protected function productFromResponse(?array $response, ?string $expectIp = null): ?array
    {
        if (! $response || ($response['result'] ?? '') !== 'success') {
            return null;
        }

        $products = $response['products']['product'] ?? [];
        if (empty($products)) {
            return null;
        }

        $product = $products[0];
        if ($expectIp !== null) {
            $rowIp = trim((string) ($product['dedicatedip'] ?? ''));
            $rowDomain = trim((string) ($product['domain'] ?? ''));
            if ($rowIp !== $expectIp && $rowDomain !== $expectIp) {
                return null;
            }
        }

        return $this->shapeProduct($product);
    }

    /**
     * Translate the verbose WHMCS product payload into the flat shape the
     * provider layer wants. Centralised so findServiceByHostname /
     * findServiceByIp / future GetClientsProducts callers can't drift.
     */
    protected function shapeProduct(array $product): array
    {
        $customFields = [];
        foreach ($product['customfields']['customfield'] ?? [] as $field) {
            $customFields[$field['name'] ?? $field['id'] ?? ''] = $field['value'] ?? '';
        }

        return [
            'id' => $product['id'] ?? null,
            'client_id' => $product['clientid'] ?? null,
            'name' => $product['translated_name'] ?? $product['name'] ?? null,
            'groupname' => $product['translated_groupname'] ?? $product['groupname'] ?? null,
            'status' => $product['status'] ?? null,
            'domain' => $product['domain'] ?? null,
            'dedicatedip' => $product['dedicatedip'] ?? null,
            'serverid' => $product['serverid'] ?? null,
            'servername' => $product['servername'] ?? null,
            'serverip' => $product['serverip'] ?? null,
            'regdate' => $product['regdate'] ?? null,
            'nextduedate' => $product['nextduedate'] ?? null,
            'billingcycle' => $product['billingcycle'] ?? null,
            'amount' => $product['recurringamount'] ?? $product['firstpaymentamount'] ?? null,
            'paymentmethod' => $product['paymentmethodname'] ?? $product['paymentmethod'] ?? null,
            'suspensionreason' => $product['suspensionreason'] ?? null,
            'ordernumber' => $product['ordernumber'] ?? null,
            'custom_fields' => $customFields,
        ];
    }

    /**
     * Suspend a service via WHMCS ModuleSuspend API.
     */
    public function suspendService(int $serviceId, string $reason = ''): array
    {
        Log::info("WHMCS: suspending service #{$serviceId}", ['reason' => $reason]);

        $response = $this->apiCall('ModuleSuspend', [
            'serviceid' => $serviceId,
            'suspendreason' => $reason,
        ]);

        if (! $response) {
            return ['success' => false, 'message' => 'WHMCS unreachable (network/transport error)'];
        }

        $success = ($response['_http_ok'] ?? false) && (($response['result'] ?? '') !== 'error');
        $message = $response['message']
            ?? ($success ? 'Service suspended' : 'Suspension failed (HTTP ' . ($response['_http_status'] ?? '?') . ')');

        return ['success' => $success, 'message' => $message];
    }

    /**
     * Unsuspend a service.
     */
    public function unsuspendService(int $serviceId): array
    {
        $response = $this->apiCall('ModuleUnsuspend', [
            'serviceid' => $serviceId,
        ]);

        if (! $response) {
            return ['success' => false, 'message' => 'WHMCS unreachable (network/transport error)'];
        }

        $success = ($response['_http_ok'] ?? false) && (($response['result'] ?? '') !== 'error');
        return ['success' => $success, 'message' => $response['message'] ?? ''];
    }

    /**
     * Open a support ticket on behalf of the client. The client will receive
     * WHMCS's standard ticket-created email.
     *
     * $params: ['clientid', 'subject', 'message', 'deptid'?, 'priority'?, 'serviceid'?]
     * — clientid is required; deptid defaults to the configured abuse department.
     */
    public function openTicket(array $params): array
    {
        $clientId = $params['clientid'] ?? null;
        if (! $clientId) {
            return ['success' => false, 'message' => 'clientid is required'];
        }

        $payload = [
            'clientid' => (int) $clientId,
            'subject' => $params['subject'] ?? 'Abuse report',
            'message' => $params['message'] ?? '',
            'deptid' => (int) ($params['deptid'] ?? config('abusedesk.whmcs.ticket_department_id', 1)),
            'priority' => $params['priority'] ?? 'High',
            'markdown' => false,
            // Open the ticket as an admin so WHMCS attributes it correctly.
            'admin' => true,
        ];
        if (! empty($params['serviceid'])) {
            $payload['serviceid'] = (int) $params['serviceid'];
        }
        // Some WHMCS installs require an admin to be attributed on OpenTicket
        // (especially when restricting departments by admin role). Resolved
        // from the per-brand whmcs_config.admin_username, otherwise the
        // global default in config/abusedesk.php (env: WHMCS_ADMIN_USERNAME).
        $adminUsername = $params['adminusername'] ?? $this->adminUsername;
        if ($adminUsername !== null && $adminUsername !== '') {
            $payload['adminusername'] = (string) $adminUsername;
            // WHMCS's OpenTicket also reads `username` to attribute the
            // ticket to an admin (the IDENTIFIER_OR_ADMIN_USERNAME field).
            $payload['username'] = (string) $adminUsername;
        }

        $response = $this->apiCall('OpenTicket', $payload);

        if (! $response) {
            return ['success' => false, 'message' => 'WHMCS unreachable (network/transport error)'];
        }

        $success = ($response['_http_ok'] ?? false) && (($response['result'] ?? '') === 'success');

        // Surface the real WHMCS error (e.g. "Invalid Department ID") instead
        // of the generic "OpenTicket failed" — these all return non-2xx with
        // the cause in `message`.
        $message = $response['message'] ?? null;
        if (! $message) {
            $message = $success
                ? 'Ticket opened'
                : 'OpenTicket failed (HTTP ' . ($response['_http_status'] ?? '?') . ')';
        }

        return [
            'success' => $success,
            'message' => $message,
            'ticket_id' => $response['id'] ?? null,
            'ticket_number' => $response['tid'] ?? null,
        ];
    }

    /**
     * WHMCS API call with full logging.
     *
     * Always returns an array on a completed HTTP round-trip (success or
     * non-2xx) so callers can read the WHMCS error message. Returns null only
     * on transport failure (timeout / DNS / TLS / etc).
     *
     * The returned array always carries `_http_status` and `_http_ok` so
     * callers can distinguish an HTTP-level failure from an API-level error.
     * If WHMCS returned a non-JSON body (rare, but seen on some endpoints),
     * the array also includes `_body_preview` for diagnostics.
     */
    protected function apiCall(string $action, array $params = []): ?array
    {
        if (! $this->isConfigured()) {
            Log::warning("WHMCS: not configured for {$action}");
            return null;
        }

        // Log request params (never log credentials)
        Log::info("WHMCS API request: {$action}", ['params' => $params]);

        try {
            $response = Http::asForm()->timeout(30)->post($this->apiUrl, array_merge($params, [
                'action' => $action,
                'identifier' => $this->apiIdentifier,
                'secret' => $this->apiSecret,
                'responsetype' => 'json',
            ]));

            $json = $response->json();
            $body = (string) $response->body();
            $status = $response->status();
            $ok = $response->successful();

            $logCtx = [
                'http' => $status,
                'result' => is_array($json) ? ($json['result'] ?? null) : null,
                'message' => is_array($json) ? ($json['message'] ?? null) : null,
                'totalresults' => is_array($json) ? ($json['totalresults'] ?? null) : null,
            ];
            if (! is_array($json)) {
                $logCtx['body_preview'] = mb_substr($body, 0, 300);
            }
            Log::info("WHMCS API response: {$action}", $logCtx);

            if (is_array($json)) {
                return $json + ['_http_status' => $status, '_http_ok' => $ok];
            }

            // Non-JSON body. If the HTTP call itself succeeded, treat as a
            // successful call with an empty payload — some WHMCS installs
            // respond to ModuleSuspend with an empty/HTML body even though
            // the action ran. Otherwise surface the body as the error message.
            return [
                '_http_status' => $status,
                '_http_ok' => $ok,
                '_body_preview' => mb_substr($body, 0, 300),
                'result' => $ok ? 'success' : 'error',
                'message' => $ok ? null : ('HTTP ' . $status . ': ' . mb_substr($body, 0, 200)),
            ];
        } catch (\Throwable $e) {
            Log::error("WHMCS API error: {$e->getMessage()}", ['action' => $action]);
            return null;
        }
    }
}
