<?php

namespace App\Jobs\Automation;

use App\Enums\ActionType;
use App\Models\AbuseCase;
use App\Models\Brand;
use App\Models\CaseAction;
use App\Models\SuspensionHistory;
use App\Services\Email\MandrillService;
use App\Services\Infrastructure\WhmcsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SuspendCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public AbuseCase $case,
    ) {
        $this->onQueue('automation');
    }

    /**
     * Serialize concurrent SuspendCustomer jobs for the same case. Two
     * CaseScored events firing within the suspend's runtime (e.g. an initial
     * dispatch plus a follow-up report linking to the same case) would
     * otherwise both pass the in-handle idempotency check — that check looks
     * at CaseAction rows, but the row isn't written until the END of the job.
     * The lock holds for the job's full timeout; a second job that arrives
     * while one is in flight is released back to the queue and will hit the
     * 5-minute CaseAction guard once the first job has committed.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->case->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(MandrillService $mandrill): void
    {
        $meta = $this->case->metadata ?? [];

        // Hard stop: law enforcement cases are never auto-suspended. LE requests
        // are for information, not for action — suspend is on the agent.
        if (! empty($meta['from_law_enforcement'])) {
            Log::info('Auto-suspend skipped: law enforcement case', ['case_id' => $this->case->id]);
            return;
        }

        // Atomic per-case lock: belt-and-braces alongside WithoutOverlapping
        // middleware. The middleware is a queue-layer guard, but if it isn't
        // applied (stale worker, queue driver quirks) two jobs can still race
        // through the in-handle CaseAction check, since the Suspended row is
        // only written at the END of the job. Cache::lock with a short TTL
        // makes the claim atomic regardless of the queue layer. We hold the
        // lock for the full job timeout and release in the finally block.
        $lock = Cache::lock('suspend_case:' . $this->case->id, $this->timeout);
        if (! $lock->get()) {
            Log::info('Auto-suspend skipped: another worker is already suspending this case', [
                'case_id' => $this->case->id,
            ]);
            return;
        }

        try {
            // Idempotency: if another worker already actioned this case in the
            // last few minutes, don't double-suspend / double-email / re-open a
            // second WHMCS ticket. Multiple automation rules matching the same
            // case, or a re-ran case:reevaluate, can otherwise cascade here.
            $recentSuspendAction = CaseAction::where('case_id', $this->case->id)
                ->where('action_type', ActionType::Suspended)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->exists();
            if ($recentSuspendAction) {
                Log::info('Auto-suspend skipped: case was already actioned in the last 5 minutes', [
                    'case_id' => $this->case->id,
                ]);
                return;
            }

            $customer = $this->case->customer;
            if ($customer && $customer->is_suspended) {
                Log::info('Customer already suspended', ['customer_id' => $customer->id]);
                return;
            }

            $serviceId = $meta['whmcs_service_id'] ?? null;
            $clientId = $meta['whmcs_client_id'] ?? null;
            $brand = $this->resolveBrand();

            // Step 1: suspend the WHMCS service (if one was resolved).
            // If serviceId is missing, the infra-lookup either never ran
            // (queue worker down?) or didn't resolve. Log loudly so the
            // operator notices a "ghost suspend" — local DB flag flipped
            // but WHMCS service still running.
            $whmcsResult = null;
            if ($serviceId) {
                $whmcsResult = $this->suspendInWhmcs($brand, (int) $serviceId);
            } else {
                Log::warning('Auto-suspend: no whmcs_service_id on case, WHMCS suspend skipped', [
                    'case_id' => $this->case->id,
                    'case_number' => $this->case->case_number,
                    'target_ip' => $this->case->target_ip,
                    'has_brand' => (bool) $brand,
                    'hint' => 'Run `php artisan queue:work --queue=automation` to process the LinkCustomerFromInfrastructure job, or click "Link Customer to" on the case detail to populate metadata manually.',
                ]);
            }

            // Step 2: flip the local customer state so downstream logic (dashboard,
            // scoring, communications) sees the suspension.
            if ($customer) {
                $customer->update(['is_suspended' => true, 'suspended_at' => now()]);

                SuspensionHistory::create([
                    'customer_id' => $customer->id,
                    'case_id' => $this->case->id,
                    'suspension_reason' => "Auto-suspended for case {$this->case->case_number} ({$this->case->abuse_type?->value})",
                    'suspended_at' => now(),
                    'whmcs_response' => $whmcsResult,
                ]);
            }

            // Step 3: open a WHMCS ticket to the client. The ticket body intentionally
            // omits the reporter identity / brand — client only sees the issue type +
            // the case number, so reporter confidentiality is preserved.
            $ticketResult = null;
            if ($clientId && $brand) {
                $ticketResult = $this->openClientTicket($brand, (int) $clientId, $serviceId ? (int) $serviceId : null);
            }

            // Step 4: notify the reporter(s) that we've actioned the case.
            $reporterNotified = $this->notifyReporters($mandrill);

            // Step 5: log + update case status.
            CaseAction::create([
                'case_id' => $this->case->id,
                'action_type' => ActionType::Suspended,
                'payload' => [
                    'customer_id' => $customer?->id,
                    'whmcs_service_id' => $serviceId,
                    'whmcs_client_id' => $clientId,
                    'whmcs_suspend' => $whmcsResult,
                    'whmcs_ticket' => $ticketResult,
                    'reporter_notified' => $reporterNotified,
                    'severity_score' => $this->case->severity_score,
                    'automated' => true,
                ],
                'note' => $this->buildActionNote($serviceId, $whmcsResult, $ticketResult, $reporterNotified),
                'created_at' => now(),
            ]);

            $this->case->update(['status' => 'actioned', 'actioned_at' => now()]);

            Log::info('Auto-suspend complete', [
                'case_id' => $this->case->id,
                'whmcs_service_id' => $serviceId,
                'whmcs_ticket_id' => $ticketResult['ticket_id'] ?? null,
                'reporter_notified' => $reporterNotified,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Pick the brand whose WHMCS owns the abused service. Infra-derived brand
     * (matched off the Virtualizor hostname for the target IP) is the ground
     * truth — that's the WHMCS the service actually exists in. case.brand_id
     * is a weaker signal: it's set at ingestion from the recipient inbox and
     * is wrong whenever the abuse mailbox is shared across brands or the
     * inbound report arrived at a forwarder. Falling through to case.brand_id
     * (then default) keeps suspends working for legacy cases that pre-date
     * the infra lookup.
     */
    protected function resolveBrand(): ?Brand
    {
        $infraName = $this->case->metadata['whmcs_brand'] ?? null;
        if ($infraName && ($brand = Brand::where('name', $infraName)->first())) {
            return $brand;
        }
        if ($this->case->brand_id && ($brand = Brand::find($this->case->brand_id))) {
            return $brand;
        }
        return Brand::getDefault();
    }

    protected function whmcsConfigFor(?Brand $brand): array
    {
        $config = $brand?->whmcs_config ?? [];
        if (empty($config['url'])) {
            $config = [
                'url' => config('abusedesk.whmcs.url'),
                'api_identifier' => config('abusedesk.whmcs.api_identifier'),
                'api_secret' => config('abusedesk.whmcs.api_secret'),
            ];
        }
        return $config;
    }

    protected function suspendInWhmcs(?Brand $brand, int $serviceId): array
    {
        $whmcs = new WhmcsService($this->whmcsConfigFor($brand));
        if (! $whmcs->isConfigured()) {
            return ['success' => false, 'message' => 'WHMCS not configured for brand ' . ($brand?->name ?? 'default')];
        }

        $reason = "Abuse case {$this->case->case_number}: " . ($this->case->abuse_type?->value ?? 'abuse');
        return $whmcs->suspendService($serviceId, $reason);
    }

    protected function openClientTicket(Brand $brand, int $clientId, ?int $serviceId): array
    {
        $whmcs = new WhmcsService($this->whmcsConfigFor($brand));
        if (! $whmcs->isConfigured()) {
            return ['success' => false, 'message' => 'WHMCS not configured'];
        }

        $type = $this->case->abuse_type?->value ?? 'abuse';
        $caseNo = $this->case->case_number;
        $target = $this->case->target_ip ?: $this->case->target_domain ?: 'your service';

        // Intentionally generic: no reporter name, no partner/brand identity.
        $subject = "Abuse notice — case {$caseNo} ({$type})";
        $message = "We received an abuse report regarding {$target} classified as {$type}.\n\n"
            . "Case reference: {$caseNo}\n"
            . "Affected service: #{$serviceId}\n\n"
            . "Your service has been suspended pending your response.\n"
            . "Please reply to this ticket explaining the cause and the remediation steps you've taken.\n\n"
            . "Failure to respond may result in permanent termination.\n";

        $deptId = $brand->whmcs_config['ticket_department_id']
            ?? config('abusedesk.whmcs.ticket_department_id');

        $params = [
            'clientid' => $clientId,
            'subject' => $subject,
            'message' => $message,
            'priority' => 'High',
        ];
        if ($deptId) {
            $params['deptid'] = (int) $deptId;
        }
        if ($serviceId) {
            $params['serviceid'] = $serviceId;
        }

        return $whmcs->openTicket($params);
    }

    protected function notifyReporters(MandrillService $mandrill): int
    {
        if (! $mandrill->isConfigured()) {
            return 0;
        }

        // Dedupe by normalized email (not just reporter.id) — the DB can have
        // multiple Reporter rows for the same address due to historic
        // case/whitespace drift, and we shouldn't mail the same inbox twice.
        $reporters = $this->case->reports()
            ->with('reporter')
            ->get()
            ->pluck('reporter')
            ->filter(fn ($r) => $r && ! empty($r->email))
            ->unique(fn ($r) => strtolower(trim($r->email)));

        if ($reporters->isEmpty()) {
            return 0;
        }

        $brand = $this->resolveBrand();
        $type = $this->case->abuse_type?->value ?? 'abuse';
        $target = $this->case->target_ip ?: $this->case->target_domain ?: '—';
        $body = "<p>Thank you for your report.</p>"
            . "<p>We have reviewed case <strong>{$this->case->case_number}</strong> regarding <code>{$target}</code> ({$type}) and suspended the offending service.</p>"
            . "<p>No further action is required on your side; we may follow up if we need additional evidence.</p>";

        $count = 0;
        foreach ($reporters as $reporter) {
            // Thread the reply: find this reporter's oldest report on this
            // case and use its Message-ID / subject. Falls back to any report
            // from a reporter sharing this email (covers duplicate Reporter
            // rows). Falls back to a plain subject if no prior email exists.
            $thread = $this->threadAnchor($reporter);
            $subject = $thread['subject'] ?? "[{$this->case->case_number}] Service suspended";
            $inReplyTo = $thread['message_id'] ?? null;

            $mandrill->send(
                toEmail: $reporter->email,
                toName: $reporter->name ?? '',
                subject: $subject,
                htmlBody: $body,
                brand: $brand,
                inReplyTo: $inReplyTo,
                caseId: $this->case->id,
            );
            $count++;
        }
        return $count;
    }

    /**
     * Find the original inbound email from $reporter on this case so we can
     * reply in-thread. Returns [message_id, subject] — subject is returned
     * with a "Re: " prefix if it didn't already start with one.
     *
     * @return array{message_id: ?string, subject: ?string}
     */
    protected function threadAnchor($reporter): array
    {
        $report = $this->case->reports()
            ->where('reporter_id', $reporter->id)
            ->orderBy('created_at')
            ->first();

        if (! $report) {
            // Duplicate-reporter drift: try any report from a reporter with
            // the same email address.
            $email = strtolower(trim((string) $reporter->email));
            $report = $this->case->reports()
                ->whereHas('reporter', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))
                ->orderBy('created_at')
                ->first();
        }

        if (! $report) {
            return ['message_id' => null, 'subject' => null];
        }

        $messageId = $report->headers['message_id'] ?? null;
        $origSubject = $report->headers['subject'] ?? null;
        $subject = null;
        if ($origSubject) {
            $subject = stripos(ltrim($origSubject), 'Re:') === 0
                ? $origSubject
                : "Re: {$origSubject}";
        }

        return ['message_id' => $messageId, 'subject' => $subject];
    }

    protected function buildActionNote(?string $serviceId, ?array $whmcs, ?array $ticket, int $reporterNotified): string
    {
        $parts = ["Auto-suspend fired for case {$this->case->case_number}."];
        if ($serviceId) {
            $parts[] = 'WHMCS service #' . $serviceId . ($whmcs['success'] ?? false ? ' suspended.' : ' suspend FAILED: ' . ($whmcs['message'] ?? 'unknown error'));
        } else {
            $parts[] = 'No WHMCS service was resolved on this case; only local customer flipped.';
        }
        if ($ticket && ($ticket['success'] ?? false)) {
            $parts[] = 'Client ticket #' . ($ticket['ticket_number'] ?? $ticket['ticket_id'] ?? '?') . ' opened.';
        } elseif ($ticket) {
            $parts[] = 'Client ticket FAILED: ' . ($ticket['message'] ?? 'unknown error');
        }
        $parts[] = "Reporter notified: {$reporterNotified}.";
        return implode(' ', $parts);
    }
}
