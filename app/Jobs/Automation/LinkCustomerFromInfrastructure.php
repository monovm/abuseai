<?php

namespace App\Jobs\Automation;

use App\Enums\ActionType;
use App\Models\AbuseCase;
use App\Models\Brand;
use App\Models\CaseAction;
use App\Models\Customer;
use App\Services\Infrastructure\InfrastructureLookupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * After a case is created with no customer_id, run the same Virtualizor +
 * WHMCS lookup the agent would do manually via "Add Customer from Infra"
 * and link/create the customer automatically.
 *
 * Lives on the automation queue, single-flighted per target IP, and
 * rate-limited to avoid storming the billing API during a DDoS burst.
 */
class LinkCustomerFromInfrastructure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        public AbuseCase $case,
    ) {
        $this->onQueue('automation');
    }

    public function middleware(): array
    {
        return [
            // Coalesce concurrent attempts for the same target so we hit
            // WHMCS at most once per IP regardless of report fan-out.
            (new WithoutOverlapping('infra-link:' . ($this->case->target_ip ?: $this->case->id)))
                ->dontRelease()
                ->expireAfter(60),
            // Cap how often we hammer the upstream WHMCS / Virtualizor APIs
            // when a brand is under a DDoS that opens many cases at once.
            // 30 lookups per minute is the published limiter; bursts release
            // back to the queue.
            new RateLimited('infra-link'),
        ];
    }

    public function handle(InfrastructureLookupService $lookup): void
    {
        $this->case->refresh();

        if ($this->case->customer_id) {
            return; // Someone (button click, prior job) already linked it.
        }
        if (! $this->case->target_ip) {
            return; // No IP to look up (domain-only or manual case).
        }

        $brand = $this->case->brand
            ?: Brand::findByIp($this->case->target_ip)
            ?: Brand::getDefault();
        if (! $brand) {
            return; // No brand to attribute to.
        }

        $record = $lookup->lookup($brand, $this->case->target_ip);
        if (! $record) {
            return; // Cache miss + lookup miss; nothing to link.
        }

        // Abuse that started before the service was registered belonged to
        // whoever held the IP at the time — not the current owner. Do not
        // match the case to them.
        if (InfrastructureLookupService::abusePredatesService($record, $this->case->effective_abuse_occurred_at)) {
            return;
        }

        $email = $record->userEmail;
        $clientId = $record->clientId;
        if (! $email && ! $clientId) {
            return; // Nothing identifiable.
        }

        // Dedupe: prefer (external_id + brand) match, then fall back to email.
        $customer = null;
        if ($clientId) {
            $customer = Customer::where('external_id', (string) $clientId)
                ->where('brand_id', $brand->id)
                ->first();
        }
        if (! $customer && $email) {
            $customer = Customer::where('email', $email)->first();
        }

        $ipAddresses = $customer?->ip_addresses ?? [];
        if (! in_array($this->case->target_ip, $ipAddresses, true)) {
            $ipAddresses[] = $this->case->target_ip;
        }

        $services = $customer?->services ?? [];
        $serviceEntry = [
            'id' => $record->serviceId,
            'domain' => $record->hostname,
            'product' => $record->product,
            'status' => $record->status,
            'dedicatedip' => $record->ip,
            'hostname' => $record->hostname,
            'provider' => $record->rawProvider,
        ];
        $found = false;
        foreach ($services as $idx => $existing) {
            if ((string) ($existing['id'] ?? null) === (string) $record->serviceId) {
                $services[$idx] = $serviceEntry;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $services[] = $serviceEntry;
        }

        $attributes = [
            'email' => $email ?: ($customer?->email ?? 'unknown@unknown'),
            'external_id' => $clientId ? (string) $clientId : ($customer?->external_id),
            'brand_id' => $brand->id,
            'ip_addresses' => $ipAddresses,
            'services' => $services,
        ];

        try {
            if ($customer) {
                $customer->fill(array_filter($attributes, fn ($v) => $v !== null))->save();
                $created = false;
            } else {
                $customer = Customer::create($attributes);
                $created = true;
            }

            $this->case->update(['customer_id' => $customer->id]);
        } catch (\Throwable $e) {
            Log::warning('LinkCustomerFromInfrastructure: persist failed', [
                'case_id' => $this->case->id,
                'target_ip' => $this->case->target_ip,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => null, // automated
            'action_type' => ActionType::NoteAdded,
            'payload' => [
                'customer_id' => $customer->id,
                'customer_email' => $customer->email,
                'brand' => $brand->name,
                'client_id' => $clientId,
                'provider' => $record->rawProvider,
                'created' => $created,
                'source' => 'auto-link',
            ],
            'note' => $created
                ? "Customer {$customer->email} auto-created from infrastructure lookup (brand: {$brand->name})"
                : "Customer {$customer->email} auto-linked from infrastructure lookup (brand: {$brand->name})",
            'created_at' => now(),
        ]);

        Log::info('LinkCustomerFromInfrastructure: linked', [
            'case_id' => $this->case->id,
            'customer_id' => $customer->id,
            'created' => $created,
            'provider' => $record->rawProvider,
        ]);
    }
}
