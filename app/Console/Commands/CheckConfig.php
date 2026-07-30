<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\EmailConnection;
use App\Models\IpAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CheckConfig extends Command
{
    protected $signature = 'abusedesk:check-config';

    protected $description = 'Validate system configuration and connectivity';

    private int $passed = 0;

    private int $failed = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('AbuseDesk Configuration Check');
        $this->line('==============================');

        $this->checkDatabase();
        $this->checkRedis();
        $this->checkAiProvider();
        $this->checkMail();
        $this->checkStorage();
        $this->checkQueue();
        $this->checkEmailConnections();
        $this->checkIpInventory();
        $this->checkBrands();
        $this->checkWhmcs();
        $this->checkVirtualizor();

        $this->newLine();
        $this->line("Summary: {$this->passed} passed, {$this->failed} failed, {$this->warnings} warnings");
        $this->newLine();

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkDatabase(): void
    {
        try {
            $pdo = DB::connection()->getPDO();
            $dbName = DB::connection()->getDatabaseName();
            $this->pass("Database: PostgreSQL connected ({$dbName})");
        } catch (\Throwable $e) {
            $this->checkFail('Database: Could not connect — '.$e->getMessage());
        }
    }

    private function checkRedis(): void
    {
        try {
            $response = Redis::ping();
            // Redis::ping() may return true or a PONG string depending on driver
            if ($response === true || $response === 'PONG' || (is_object($response) && (string) $response === 'PONG')) {
                $this->pass('Redis: Connected');
            } else {
                $this->checkFail('Redis: Unexpected ping response');
            }
        } catch (\Throwable $e) {
            $this->checkFail('Redis: Could not connect — '.$e->getMessage());
        }
    }

    private function checkAiProvider(): void
    {
        $provider = config('ai.active_provider', 'claude');
        $providerConfig = config("ai.providers.{$provider}");

        if (! $providerConfig) {
            $this->checkFail("AI Provider: Unknown provider '{$provider}'");

            return;
        }

        $providerName = $providerConfig['name'] ?? $provider;
        $model = $providerConfig['model'] ?? 'unknown';

        $envKey = match ($provider) {
            'claude' => 'ANTHROPIC_API_KEY',
            'openai' => 'OPENAI_API_KEY',
            default => null,
        };

        if ($envKey && ! empty(env($envKey))) {
            $this->pass("AI Provider: {$providerName} ({$model}) — API key set");
        } elseif ($envKey) {
            $this->checkFail("AI Provider: {$envKey} not set (active provider: {$provider})");
        } else {
            $this->warning("AI Provider: No known env key for provider '{$provider}'");
        }
    }

    private function checkMail(): void
    {
        if (! empty(config('abusedesk.mandrill_key'))) {
            $this->pass('Mail: MANDRILL_KEY set');
        } else {
            $this->checkFail('Mail: MANDRILL_KEY not set');
        }
    }

    private function checkStorage(): void
    {
        $path = storage_path('app/emails');

        if (! is_dir($path)) {
            // Attempt to create it
            if (@mkdir($path, 0755, true)) {
                $this->pass('Storage: emails directory created and writable');
            } else {
                $this->checkFail('Storage: emails directory does not exist and could not be created');
            }

            return;
        }

        if (is_writable($path)) {
            $this->pass('Storage: emails directory writable');
        } else {
            $this->checkFail('Storage: emails directory is not writable');
        }
    }

    private function checkQueue(): void
    {
        if (config('horizon') !== null) {
            $this->pass('Queue: Horizon configured');
        } else {
            $this->warning('Queue: Horizon not configured');
        }
    }

    private function checkEmailConnections(): void
    {
        try {
            $count = EmailConnection::where('is_active', true)->count();
            if ($count > 0) {
                $this->pass("Email Connections: {$count} active connection".($count !== 1 ? 's' : ''));
            } else {
                $this->warning('Email Connections: No active connections found');
            }
        } catch (\Throwable) {
            $this->warning('Email Connections: Could not query (table may not exist)');
        }
    }

    private function checkIpInventory(): void
    {
        try {
            $count = IpAddress::count();
            if ($count > 0) {
                $this->pass("IP Inventory: {$count} IP".($count !== 1 ? 's' : '').' loaded');
            } else {
                $this->warning('IP Inventory: No IPs loaded');
            }
        } catch (\Throwable) {
            $this->warning('IP Inventory: Could not query (table may not exist)');
        }
    }

    private function checkBrands(): void
    {
        try {
            $count = Brand::count();
            if ($count > 0) {
                $this->pass("Brands: {$count} brand".($count !== 1 ? 's' : '').' configured');
            } else {
                $this->warning('Brands: No brands configured');
            }
        } catch (\Throwable) {
            $this->warning('Brands: Could not query (table may not exist)');
        }
    }

    private function checkWhmcs(): void
    {
        $url = config('abusedesk.whmcs.url');
        if (! empty($url)) {
            $this->info("  WHMCS: {$url} (configured)");
        } else {
            $this->info('  WHMCS: not configured');
        }
    }

    private function checkVirtualizor(): void
    {
        $url = config('abusedesk.virtualizor.url');
        if (! empty($url)) {
            $this->info("  Virtualizor: {$url} (configured)");
        } else {
            $this->info('  Virtualizor: not configured');
        }
    }

    private function pass(string $message): void
    {
        $this->info("✓ {$message}");
        $this->passed++;
    }

    private function checkFail(string $message): void
    {
        $this->error("✗ {$message}");
        $this->failed++;
    }

    private function warning(string $message): void
    {
        $this->warn("⚠ {$message}");
        $this->warnings++;
    }
}
