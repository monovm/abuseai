<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HostBill admin API client. Auth is api_id + api_key as form params on
 * `/admin/api.php`. Most read calls return a flat envelope:
 *   { "success": true, "accounts": [...] }
 * Errors come back as { "success": false, "error": "..." } with HTTP 200,
 * so we always inspect the body rather than relying on status codes.
 *
 * @see https://api2.hostbillapp.com/
 */
class HostbillService
{
    protected string $apiUrl;
    protected string $apiId;
    protected string $apiKey;

    public function __construct(?array $config = null)
    {
        $config = $config ?? [];
        $this->apiUrl = rtrim($config['url'] ?? '', '/');
        $this->apiId = $config['api_id'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiUrl) && ! empty($this->apiId) && ! empty($this->apiKey);
    }

    /**
     * Find a HostBill account by IP. HostBill's `getAccounts` accepts a
     * generic filter map; we pass `dedicated_ip` as the search key. If
     * that returns nothing, fall back to a paginated scan over all
     * accounts in case the IP is held in a custom field.
     */
    public function findAccountByIp(string $ip, int $maxScan = 5000): ?array
    {
        $resp = $this->call('getAccounts', ['dedicated_ip' => $ip]);
        $account = $this->firstAccount($resp);
        if ($account) {
            return $this->shapeAccount($account);
        }

        // Some HostBill installs don't expose dedicated_ip as a search
        // key — fall back to a paginated scan filtering client-side.
        $page = 1;
        $perPage = 100;
        $scanned = 0;

        while ($scanned < $maxScan) {
            $resp = $this->call('getAccounts', [
                'page' => $page,
                'rows_per_page' => $perPage,
            ]);
            $accounts = $resp['accounts'] ?? [];
            if (empty($accounts)) {
                return null;
            }

            foreach ($accounts as $a) {
                $rowIp = trim((string) ($a['dedicated_ip'] ?? ''));
                if ($rowIp !== '' && $rowIp === $ip) {
                    return $this->shapeAccount($a);
                }
            }

            $scanned += count($accounts);
            if (count($accounts) < $perPage) {
                return null;
            }
            $page++;
        }

        return null;
    }

    public function findAccountByHostname(string $hostname): ?array
    {
        $resp = $this->call('getAccounts', ['domain' => $hostname]);
        $account = $this->firstAccount($resp);
        return $account ? $this->shapeAccount($account) : null;
    }

    public function suspendAccount(string $accountId, string $reason): array
    {
        $resp = $this->call('accountSuspend', [
            'id' => $accountId,
            'reason' => $reason,
        ]);
        return $this->shapeActionResult($resp, 'suspended');
    }

    public function unsuspendAccount(string $accountId): array
    {
        $resp = $this->call('accountUnsuspend', ['id' => $accountId]);
        return $this->shapeActionResult($resp, 'unsuspended');
    }

    protected function firstAccount(?array $resp): ?array
    {
        $accounts = $resp['accounts'] ?? [];
        return $accounts[0] ?? null;
    }

    protected function shapeAccount(array $a): array
    {
        return [
            'id' => $a['id'] ?? null,
            'client_id' => $a['client_id'] ?? null,
            'product_name' => $a['name'] ?? null,
            'category' => $a['category'] ?? null,
            'status' => strtolower((string) ($a['status'] ?? 'unknown')),
            'domain' => $a['domain'] ?? null,
            'dedicated_ip' => $a['dedicated_ip'] ?? null,
            'date_created' => $a['date_created'] ?? null,
            'next_due' => $a['next_due'] ?? null,
            'billing_cycle' => $a['billingcycle'] ?? null,
            'recurring_amount' => $a['recurring_amount'] ?? ($a['amount'] ?? null),
            'payment_method' => $a['payment_module'] ?? null,
            'reason' => $a['reason'] ?? null,
            'order_id' => $a['order_id'] ?? null,
            'server' => $a['server'] ?? null,
            'raw' => $a,
        ];
    }

    protected function shapeActionResult(?array $resp, string $verb): array
    {
        if (! $resp) {
            return ['success' => false, 'message' => "HostBill unreachable for {$verb}"];
        }
        $ok = ($resp['success'] ?? false) === true;
        return [
            'success' => $ok,
            'message' => $resp['info'][0] ?? ($resp['error'] ?? ($ok ? "Account {$verb}" : "{$verb} failed")),
            'raw' => $resp,
        ];
    }

    protected function call(string $action, array $params = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $resp = Http::asForm()
                ->withoutVerifying()
                ->timeout(30)
                ->post($this->apiUrl, array_merge($params, [
                    'api_id' => $this->apiId,
                    'api_key' => $this->apiKey,
                    'call' => $action,
                ]));

            $json = $resp->json();
            Log::info('HostBill API', [
                'action' => $action, 'http' => $resp->status(),
                'success' => is_array($json) ? ($json['success'] ?? null) : null,
            ]);
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::error('HostBill API error', ['action' => $action, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
