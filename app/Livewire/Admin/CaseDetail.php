<?php

namespace App\Livewire\Admin;

use App\Concerns\AuthorizesAdmin;
use App\Enums\ActionType;
use App\Enums\CaseStatus;
use App\Jobs\AI\AnalyseEvidence;
use App\Jobs\AI\DraftCustomerNotice;
use App\Jobs\AI\DraftReporterReply;
use App\Jobs\AI\RescoreCase;
use App\Enums\ProviderCapability;
use App\Models\AbuseCase;
use App\Models\Brand;
use App\Models\CaseAction;
use App\Models\Customer;
use App\Models\SentEmail;
use App\Services\Email\MandrillService;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use App\Services\Infrastructure\InfrastructureLookupService;
use App\Services\Infrastructure\ProviderRegistry;
use App\Services\Infrastructure\UrlTakedownService;
use App\Services\Infrastructure\WhmcsService;
use Livewire\Component;

class CaseDetail extends Component
{
    use AuthorizesAdmin;

    public AbuseCase $case;
    public string $newNote = '';
    public string $newStatus = '';

    // Infrastructure data
    public ?array $infraData = null;
    public bool $infraLoading = false;

    // Report pagination
    public int $reportsPerPage = 20;
    public int $reportsPage = 1;

    // Merge form
    public bool $showMergeForm = false;
    public string $mergeTargetNumber = '';

    // Reply form
    public bool $showReplyForm = false;
    public string $replyTo = '';
    public string $replyToName = '';
    public string $replySubject = '';
    public string $replyBody = '';
    public string $replyBrandId = '';

    // Manual target IP attachment
    public bool $showIpForm = false;
    public string $manualTargetIp = '';

    // Close case form
    public bool $showCloseForm = false;
    public string $closeReason = '';
    public string $closeNote = '';

    public function mount(AbuseCase $case): void
    {
        $this->case = $case;
        $this->newStatus = $case->status->value;
        // Reply defaults to the brand the report was received via (so the
        // reporter sees a reply from the inbox they wrote to). Falls back to
        // the action brand, then the system default.
        $this->replyBrandId = $case->received_via_brand_id
            ?? $case->brand_id
            ?? (Brand::getDefault()?->id ?? '');

        // Auto-load infrastructure (uses cache if available, otherwise calls API)
        if ($case->target_ip) {
            $this->loadInfrastructure();
        }
    }

    public function loadInfrastructure(): void
    {
        if (! $this->case->target_ip) {
            return;
        }

        $brand = $this->resolveBrandForLookup();
        if (! $brand) {
            $this->infraData = null;
            return;
        }

        try {
            $record = app(InfrastructureLookupService::class)->lookup($brand, $this->case->target_ip);
            $this->infraData = $record ? $this->serializeRecord($record, $brand) : null;
        } catch (\Throwable $e) {
            $this->infraData = ['errors' => [$e->getMessage()]];
        }
    }

    public function openIpForm(): void
    {
        $this->showIpForm = true;
        $this->manualTargetIp = $this->case->target_ip ?? '';
        $this->resetErrorBag('manualTargetIp');
    }

    public function cancelIpForm(): void
    {
        $this->showIpForm = false;
        $this->manualTargetIp = '';
        $this->resetErrorBag('manualTargetIp');
    }

    public function attachTargetIp(): void
    {
        $this->validate([
            'manualTargetIp' => ['required', 'ip'],
        ]);

        $old = $this->case->target_ip;
        $new = trim($this->manualTargetIp);

        if ($old === $new) {
            $this->showIpForm = false;
            return;
        }

        $this->case->update(['target_ip' => $new]);

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::NoteAdded,
            'payload' => ['field' => 'target_ip', 'from' => $old, 'to' => $new],
            'note' => $old
                ? "Target IP changed from {$old} to {$new}"
                : "Target IP manually attached: {$new}",
            'created_at' => now(),
        ]);

        $this->showIpForm = false;
        $this->manualTargetIp = '';
        $this->case->refresh();

        // Refresh infrastructure data for the new IP
        $this->infraData = null;
        $this->loadInfrastructure();

        session()->flash('ip-success', $old
            ? "Target IP updated to {$new}."
            : "Target IP {$new} attached to case.");
    }

    public function refreshInfrastructure(): void
    {
        if (! $this->case->target_ip) {
            return;
        }

        $brand = $this->resolveBrandForLookup();
        if (! $brand) {
            $this->infraData = null;
            return;
        }

        try {
            $record = app(InfrastructureLookupService::class)->refresh($brand, $this->case->target_ip);
            $this->infraData = $record ? $this->serializeRecord($record, $brand) : null;
            session()->flash('infra-success', 'Infrastructure data refreshed.');
        } catch (\Throwable $e) {
            $this->infraData = ['errors' => [$e->getMessage()]];
        }
    }

    /**
     * Brand whose provider we should query for this case. Prefer the
     * case's assigned brand; fall back to discovering one by IP so the
     * panel still works on an unassigned case during triage.
     */
    protected function resolveBrandForLookup(): ?Brand
    {
        if ($this->case->brand) {
            return $this->case->brand;
        }
        if ($this->case->target_ip) {
            return Brand::findByIp($this->case->target_ip);
        }
        return null;
    }

    /**
     * Flatten a ServiceRecord into the array shape the blade view consumes.
     * Keeping a single conversion point means the view never branches on
     * provider, only on the capabilities flag and presence of fields.
     */
    protected function serializeRecord(ServiceRecord $record, Brand $brand): array
    {
        return [
            'service_id' => $record->serviceId,
            'client_id' => $record->clientId,
            'hostname' => $record->hostname,
            'ip' => $record->ip,
            'product' => $record->product,
            'product_group' => $record->productGroup,
            'status' => $record->status,
            'status_label' => $record->statusLabel,
            'user_email' => $record->userEmail,
            'registered_at' => $record->registeredAt?->format('Y-m-d'),
            'next_due_at' => $record->nextDueAt?->format('Y-m-d'),
            'billing_cycle' => $record->billingCycle,
            'amount' => $record->amount,
            'payment_method' => $record->paymentMethod,
            'suspension_reason' => $record->suspensionReason,
            'order_number' => $record->orderNumber,
            'server_name' => $record->serverName,
            'server_ip' => $record->serverIp,
            'admin_url' => $record->adminUrl,
            'capabilities' => array_map(fn ($c) => $c->value, $record->capabilities),
            'raw_fields' => $record->rawFields,
            'provider' => $record->rawProvider,
            'provider_type' => $record->providerType,
            'brand_id' => $brand->id,
            'brand_name' => $brand->name,
        ];
    }

    /** Re-run lookup (cached) and return the live ServiceRecord for action handlers. */
    protected function fetchServiceRecord(): ?ServiceRecord
    {
        $brand = $this->resolveBrandForLookup();
        if (! $brand || ! $this->case->target_ip) {
            return null;
        }
        return app(InfrastructureLookupService::class)->lookup($brand, $this->case->target_ip);
    }

    public function suspendService(): void
    {
        $this->authorizeAdmin();
        $this->suspendWhmcsService();
    }

    public function openReply(?string $toEmail = null, ?string $toName = null): void
    {
        // Auto-fill from reporter of first report
        if (! $toEmail) {
            $firstReport = $this->case->reports()->with('reporter')->oldest()->first();
            $toEmail = $firstReport?->reporter?->email ?? '';
            $toName = $firstReport?->reporter?->name ?? '';
        }

        $this->replyTo = $toEmail;
        $this->replyToName = $toName;
        $this->replySubject = "Re: [{$this->case->case_number}] " . ucfirst($this->case->abuse_type->label());
        $this->replyBody = '';
        $this->showReplyForm = true;
    }

    public function useAiDraft(string $actionId): void
    {
        $action = CaseAction::find($actionId);
        if ($action && isset($action->payload['draft_body'])) {
            $this->replyBody = $action->payload['draft_body'];
            $this->showReplyForm = true;

            if (empty($this->replyTo)) {
                $this->openReply();
            }
        }
    }

    public function dismissAiDraft(string $actionId): void
    {
        // Hide the draft from the right rail by tagging it dismissed in payload.
        // The audit_logs row stays untouched — case_actions is the working set.
        $action = CaseAction::find($actionId);
        if ($action && $action->case_id === $this->case->id) {
            $payload = $action->payload ?? [];
            $payload['dismissed'] = true;
            $payload['dismissed_at'] = now()->toIso8601String();
            $action->update(['payload' => $payload]);
        }
    }

    public function sendReply(MandrillService $mandrill): void
    {
        $this->validate([
            'replyTo' => ['required', 'email'],
            'replySubject' => ['required', 'string', 'max:255'],
            'replyBody' => ['required', 'string', 'min:5'],
        ]);

        $brand = $this->replyBrandId ? Brand::find($this->replyBrandId) : Brand::getDefault();

        if (! $mandrill->canSendFor($brand)) {
            session()->flash('reply-error', 'No email transport configured. Set MANDRILL_KEY in .env, or give this brand an inbox connection / SMTP fallback settings.');
            return;
        }

        // Find original message-id for threading
        $firstReport = $this->case->reports()->with('reporter')->oldest()->first();
        $inReplyTo = $firstReport?->headers['message_id'] ?? null;

        $htmlBody = nl2br(e($this->replyBody));

        $sentEmail = $mandrill->send(
            toEmail: $this->replyTo,
            toName: $this->replyToName,
            subject: $this->replySubject,
            htmlBody: $htmlBody,
            brand: $brand,
            inReplyTo: $inReplyTo,
            caseId: $this->case->id,
        );

        // Log the action
        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::EmailSent,
            'payload' => [
                'to' => $this->replyTo,
                'subject' => $this->replySubject,
                'brand' => $brand?->name,
                'status' => $sentEmail->status,
                'sent_email_id' => $sentEmail->id,
            ],
            'note' => "Email sent to {$this->replyTo}: {$this->replySubject}",
            'created_at' => now(),
        ]);

        if (! $sentEmail->wasAccepted()) {
            session()->flash('reply-error', "Email was not sent ({$sentEmail->failureReason()}). Check the Mandrill API key and that the sending domain is verified.");
        } elseif (($sentEmail->metadata['transport'] ?? null) === 'smtp_fallback') {
            session()->flash('reply-success', "Email sent to {$this->replyTo} via the brand's own SMTP (Mandrill was unavailable)");
        } else {
            session()->flash('reply-success', "Email sent to {$this->replyTo}");
        }

        $this->showReplyForm = false;
        $this->replyBody = '';
        $this->case->refresh();
    }

    public function cancelReply(): void
    {
        $this->showReplyForm = false;
    }

    public function openMergeForm(): void
    {
        $this->showMergeForm = true;
        $this->mergeTargetNumber = '';
    }

    public function cancelMerge(): void
    {
        $this->showMergeForm = false;
        $this->mergeTargetNumber = '';
    }

    public function mergeIntoCase(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'mergeTargetNumber' => ['required', 'string', 'regex:/^ABU-\d{4}-\d{5}$/'],
        ]);

        $target = \App\Models\AbuseCase::where('case_number', $this->mergeTargetNumber)->first();

        if (! $target) {
            session()->flash('merge-error', "Case {$this->mergeTargetNumber} not found.");
            return;
        }

        if ($target->id === $this->case->id) {
            session()->flash('merge-error', 'Cannot merge a case into itself.');
            return;
        }

        $merger = app(\App\Services\CaseEngine\CaseMergeService::class);
        $merger->merge($target, $this->case, auth()->id());

        session()->flash('merge-success', "This case has been merged into {$target->case_number}.");
        $this->showMergeForm = false;
        $this->case->refresh();
    }

    public function addNote(): void
    {
        if (empty(trim($this->newNote))) {
            return;
        }

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::NoteAdded,
            'note' => $this->newNote,
            'created_at' => now(),
        ]);

        $this->newNote = '';
        $this->case->refresh();
        session()->flash('note-success', 'Note added.');
    }

    public function updateStatus(): void
    {
        $old = $this->case->status->value;
        $new = $this->newStatus;

        if ($old === $new) {
            return;
        }

        $timestamps = [];
        if ($new === 'actioned') {
            $timestamps['actioned_at'] = now();
        }
        if ($new === 'resolved') {
            $timestamps['resolved_at'] = now();
        }
        if ($new === 'closed') {
            $timestamps['closed_at'] = now();
        }

        $this->case->update(array_merge(['status' => $new], $timestamps));

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::StatusChanged,
            'payload' => ['from' => $old, 'to' => $new],
            'note' => "Status changed from {$old} to {$new}",
            'created_at' => now(),
        ]);

        $this->case->refresh();
    }

    public function openCloseForm(): void
    {
        $this->showCloseForm = true;
        $this->closeReason = '';
        $this->closeNote = '';
    }

    public function cancelClose(): void
    {
        $this->showCloseForm = false;
        $this->closeReason = '';
        $this->closeNote = '';
    }

    /**
     * Close the case with a reason. Stores the reason on the case
     * (metadata.closure_reason / metadata.closure_note) and writes a
     * timeline entry so agents can see why it was closed.
     */
    public function closeCase(): void
    {
        $this->authorizeAdmin();

        $validReasons = [
            'not_correct' => 'Not correct',
            'false_positive' => 'False positive',
            'duplicate' => 'Duplicate',
            'no_action_needed' => 'No action needed',
            'marketing_or_spam_pitch' => 'Marketing / SEO pitch (not abuse)',
            'resolved_offline' => 'Resolved offline',
            'other' => 'Other',
        ];

        $reasonKey = $this->closeReason;
        if (! isset($validReasons[$reasonKey])) {
            session()->flash('close-error', 'Please select a closure reason.');
            return;
        }

        $reasonLabel = $validReasons[$reasonKey];
        $note = trim($this->closeNote);
        if ($reasonKey === 'other' && $note === '') {
            session()->flash('close-error', 'A note is required when closing with reason "Other".');
            return;
        }

        $old = $this->case->status->value;

        if ($old === 'closed') {
            session()->flash('close-error', 'Case is already closed.');
            return;
        }

        $metadata = $this->case->metadata ?? [];
        $metadata['closure_reason'] = $reasonKey;
        $metadata['closure_reason_label'] = $reasonLabel;
        if ($note !== '') {
            $metadata['closure_note'] = $note;
        }
        $metadata['closed_by_user_id'] = auth()->id();

        $this->case->update([
            'status' => CaseStatus::Closed,
            'closed_at' => now(),
            'metadata' => $metadata,
        ]);

        $timelineNote = "Case closed — {$reasonLabel}";
        if ($note !== '') {
            $timelineNote .= ": {$note}";
        }

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::Closed,
            'payload' => [
                'from' => $old,
                'to' => 'closed',
                'reason' => $reasonKey,
                'reason_label' => $reasonLabel,
                'note' => $note !== '' ? $note : null,
            ],
            'note' => $timelineNote,
            'created_at' => now(),
        ]);

        $this->showCloseForm = false;
        $this->closeReason = '';
        $this->closeNote = '';
        $this->case->refresh();
        $this->newStatus = $this->case->status->value;

        session()->flash('close-success', 'Case closed.');
    }

    /**
     * Collect target URLs from the case's reports that belong to a host the
     * resolved brand's url_takedown config can handle. Cases don't carry
     * target_url directly — it lives on the report rows — so we surface
     * them up here for the takedown UI.
     *
     * @return array<int, string>
     */
    public function takedownCandidates(UrlTakedownService $takedown): array
    {
        $brand = $this->resolveBrandForLookup();
        if (! $brand || ! $takedown->isConfigured($brand)) {
            return [];
        }

        $urls = $this->case->reports()
            ->whereNotNull('target_url')
            ->where('target_url', '!=', '')
            ->pluck('target_url')
            ->map(fn ($u) => $takedown->cleanUrl((string) $u))
            ->unique()
            ->filter(fn ($u) => $takedown->handlesUrl($brand, $u))
            ->values()
            ->all();

        return $urls;
    }

    public function takedownUrl(string $url, UrlTakedownService $takedown): void
    {
        $this->authorizeAdmin();

        $brand = $this->resolveBrandForLookup();
        if (! $brand) {
            session()->flash('infra-error', 'No brand resolved for this case — cannot run takedown.');
            return;
        }

        $result = $takedown->takedown($brand, $url);

        if (! $result['success']) {
            session()->flash('infra-error', 'URL takedown failed: ' . ($result['error'] ?? 'unknown error'));
            return;
        }

        CaseAction::create([
            'case_id' => $this->case->id,
            'action_type' => ActionType::NoteAdded,
            'payload' => [
                'type' => 'url_takedown',
                'url' => $result['url'],
                'remote_id' => $result['id'] ?? null,
                'brand' => $brand->name,
            ],
            'note' => "URL takedown via {$brand->name}: deleted {$result['url']} (remote id {$result['id']}). "
                . ($result['message'] ?? ''),
            'created_at' => now(),
        ]);

        $this->case->update(['last_seen_at' => now()]);

        session()->flash('infra-success', "URL deleted: {$result['url']} (remote id {$result['id']}).");
    }

    public function suspendWhmcsService(): void
    {
        $this->authorizeAdmin();

        $brand = $this->resolveBrandForLookup();
        $record = $this->fetchServiceRecord();

        if (! $brand || ! $record) {
            session()->flash('infra-error', 'No service found to suspend.');
            return;
        }
        if (! $record->can(ProviderCapability::Suspend)) {
            session()->flash('infra-error', 'This brand\'s provider does not support remote suspend.');
            return;
        }

        $reason = "Abuse case {$this->case->case_number}";
        $provider = app(ProviderRegistry::class)->for($brand);
        $result = $provider->suspend($brand, $record, $reason);

        if ($result->success) {
            CaseAction::create([
                'case_id' => $this->case->id,
                'actor_id' => auth()->id(),
                'action_type' => ActionType::Suspended,
                'payload' => [
                    'service_id' => $record->serviceId,
                    'client_id' => $record->clientId,
                    'provider' => $record->rawProvider,
                    'brand' => $brand->name,
                    'result' => $result->raw,
                ],
                'note' => "Service #{$record->serviceId} suspended via {$provider->name()}. Reason: {$reason}",
                'created_at' => now(),
            ]);

            $this->case->update([
                'status' => 'actioned',
                'actioned_at' => now(),
            ]);
            $this->case->refresh();

            session()->flash('infra-success', "Service #{$record->serviceId} suspended successfully.");
            $this->refreshInfrastructure();
        } else {
            session()->flash('infra-error', "Suspend failed: {$result->message}");
        }
    }

    /**
     * Reverse a suspend — typically after the customer has responded to
     * the abuse ticket and remediated the issue. Mirrors the suspend
     * path: provider call, CaseAction log, optional local-customer flag
     * flip, and a status note on the case timeline.
     */
    public function unsuspendService(): void
    {
        $this->authorizeAdmin();

        $brand = $this->resolveBrandForLookup();
        $record = $this->fetchServiceRecord();

        if (! $brand || ! $record) {
            session()->flash('infra-error', 'No service found to unsuspend.');
            return;
        }
        if (! $record->can(ProviderCapability::Unsuspend)) {
            session()->flash('infra-error', 'This brand\'s provider does not support remote unsuspend.');
            return;
        }

        $provider = app(ProviderRegistry::class)->for($brand);
        $result = $provider->unsuspend($brand, $record);

        if (! $result->success) {
            session()->flash('infra-error', "Unsuspend failed: {$result->message}");
            return;
        }

        // Flip the local customer flag back so the dashboard, scoring,
        // and reporter comms see the service as live again. Mirrors
        // SuspendCustomer:113-123 in reverse.
        if ($this->case->customer && $this->case->customer->is_suspended) {
            $this->case->customer->update([
                'is_suspended' => false,
                'suspended_at' => null,
            ]);
        }

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::Unsuspended,
            'payload' => [
                'service_id' => $record->serviceId,
                'client_id' => $record->clientId,
                'provider' => $record->rawProvider,
                'brand' => $brand->name,
                'result' => $result->raw,
                'manual' => true,
            ],
            'note' => "Service #{$record->serviceId} unsuspended via {$provider->name()}.",
            'created_at' => now(),
        ]);

        // Flip case status back to investigating so the agent has a
        // clear next step. We deliberately don't auto-resolve — the
        // agent still needs to confirm the remediation worked.
        if ($this->case->status === \App\Enums\CaseStatus::Actioned) {
            $this->case->update([
                'status' => \App\Enums\CaseStatus::Investigating,
                'actioned_at' => null,
            ]);
        }
        $this->case->refresh();
        $this->refreshInfrastructure();

        session()->flash('infra-success', "Service #{$record->serviceId} unsuspended.");
    }

    /**
     * Open a WHMCS support ticket to the client without suspending the
     * service. Use case: medium-severity reports where we want to notify
     * the customer and force a response, but the score isn't high enough
     * to warrant the automatic suspend rule.
     *
     * The ticket body intentionally omits the reporter identity / brand —
     * client only sees the issue type + the case number, mirroring the
     * confidentiality posture of the auto-suspend ticket.
     */
    public function openClientTicket(): void
    {
        $this->authorizeAdmin();

        $brand = $this->resolveBrandForLookup();
        $record = $this->fetchServiceRecord();

        if (! $brand || ! $record) {
            session()->flash('infra-error', 'No service found to ticket.');
            return;
        }
        if (! $record->clientId) {
            session()->flash('infra-error', 'No client id on the resolved service — cannot open a ticket.');
            return;
        }

        $result = $this->postWarningTicket($brand, (int) $record->clientId, $record->serviceId ? (int) $record->serviceId : null);

        if (! ($result['success'] ?? false)) {
            session()->flash('infra-error', 'Open ticket failed: ' . ($result['message'] ?? 'unknown'));
            return;
        }

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::EmailSent,
            'payload' => [
                'kind' => 'whmcs_warning_ticket',
                'service_id' => $record->serviceId,
                'client_id' => $record->clientId,
                'brand' => $brand->name,
                'whmcs_ticket' => $result,
            ],
            'note' => "Warning ticket opened to client #{$record->clientId} via WHMCS (no suspend).",
            'created_at' => now(),
        ]);

        $this->case->refresh();
        session()->flash('infra-success', 'Warning ticket opened to client.');
    }

    /**
     * Suspend the service AND open a client ticket in one click — the
     * manual equivalent of the SuspendCustomer automation job, for
     * cases that were under the auto-suspend threshold but the agent
     * still wants to action.
     */
    public function suspendAndNotifyClient(): void
    {
        $this->authorizeAdmin();

        $brand = $this->resolveBrandForLookup();
        $record = $this->fetchServiceRecord();

        if (! $brand || ! $record) {
            session()->flash('infra-error', 'No service found to suspend.');
            return;
        }
        if (! $record->can(ProviderCapability::Suspend)) {
            session()->flash('infra-error', 'This brand\'s provider does not support remote suspend.');
            return;
        }

        $reason = "Abuse case {$this->case->case_number}";
        $provider = app(ProviderRegistry::class)->for($brand);
        $suspendResult = $provider->suspend($brand, $record, $reason);

        if (! $suspendResult->success) {
            session()->flash('infra-error', "Suspend failed: {$suspendResult->message}");
            return;
        }

        $ticketResult = null;
        if ($record->clientId) {
            $ticketResult = $this->postWarningTicket($brand, (int) $record->clientId, $record->serviceId ? (int) $record->serviceId : null, suspended: true);
        }

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::Suspended,
            'payload' => [
                'service_id' => $record->serviceId,
                'client_id' => $record->clientId,
                'provider' => $record->rawProvider,
                'brand' => $brand->name,
                'result' => $suspendResult->raw,
                'whmcs_ticket' => $ticketResult,
                'manual' => true,
            ],
            'note' => "Service #{$record->serviceId} suspended via {$provider->name()}"
                . ($ticketResult && ($ticketResult['success'] ?? false) ? ' + client ticket opened' : '')
                . ". Reason: {$reason}",
            'created_at' => now(),
        ]);

        $this->case->update(['status' => 'actioned', 'actioned_at' => now()]);
        $this->case->refresh();
        $this->refreshInfrastructure();

        $msg = "Service #{$record->serviceId} suspended";
        if ($ticketResult && ($ticketResult['success'] ?? false)) {
            $msg .= ' and client ticket opened.';
        } elseif ($ticketResult) {
            $msg .= ', but ticket failed: ' . ($ticketResult['message'] ?? 'unknown');
        } else {
            $msg .= ' (no client id — ticket skipped).';
        }
        session()->flash('infra-success', $msg);
    }

    /**
     * Build and send the WHMCS support ticket body. Shared by the
     * "warn only" and "suspend + warn" paths so the message wording
     * stays in sync. Reporter identity is deliberately not disclosed
     * to the client — same posture as SuspendCustomer's auto path.
     */
    protected function postWarningTicket(Brand $brand, int $clientId, ?int $serviceId, bool $suspended = false): array
    {
        $whmcs = new WhmcsService($this->whmcsConfigForBrand($brand));
        if (! $whmcs->isConfigured()) {
            return ['success' => false, 'message' => 'WHMCS not configured for brand ' . $brand->name];
        }

        $type = $this->case->abuse_type?->value ?? 'abuse';
        $caseNo = $this->case->case_number;
        $target = $this->case->target_ip ?: $this->case->target_domain ?: 'your service';

        $subject = "Abuse notice — case {$caseNo} ({$type})";
        $body = "We received an abuse report regarding {$target} classified as {$type}.\n\n"
            . "Case reference: {$caseNo}\n"
            . ($serviceId ? "Affected service: #{$serviceId}\n\n" : "\n");
        $body .= $suspended
            ? "Your service has been suspended pending your response.\n"
                . "Please reply to this ticket explaining the cause and the remediation steps you've taken.\n\n"
                . "Failure to respond may result in permanent termination.\n"
            : "Please review the activity from your service and reply to this ticket "
                . "explaining the cause and the remediation steps you have taken.\n\n"
                . "Failure to respond may result in service suspension.\n";

        $deptId = $brand->whmcs_config['ticket_department_id']
            ?? config('abusedesk.whmcs.ticket_department_id');

        $params = [
            'clientid' => $clientId,
            'subject' => $subject,
            'message' => $body,
            'priority' => $suspended ? 'High' : 'Medium',
        ];
        if ($deptId) {
            $params['deptid'] = (int) $deptId;
        }
        if ($serviceId) {
            $params['serviceid'] = $serviceId;
        }

        return $whmcs->openTicket($params);
    }

    /**
     * Build a WhmcsService config from a brand row, falling back to env
     * defaults. Mirrors SuspendCustomer::whmcsConfigFor() so manual and
     * automated tickets share the same connection setup.
     */
    protected function whmcsConfigForBrand(Brand $brand): array
    {
        $cfg = is_array($brand->whmcs_config ?? null) ? $brand->whmcs_config : [];
        if (empty($cfg['url']) || empty($cfg['api_identifier']) || empty($cfg['api_secret'])) {
            $cfg = [
                'url' => config('abusedesk.whmcs.url'),
                'api_identifier' => config('abusedesk.whmcs.api_identifier'),
                'api_secret' => config('abusedesk.whmcs.api_secret'),
            ];
        }
        return $cfg;
    }

    public function addCustomerFromInfrastructure(): void
    {
        $brand = $this->resolveBrandForLookup();
        $record = $this->fetchServiceRecord();

        if (! $brand || ! $record) {
            session()->flash('infra-error', 'No infrastructure data available.');
            return;
        }
        if (! $record->userEmail && ! $record->clientId) {
            session()->flash('infra-error', 'Provider returned no user email or client ID — nothing to link.');
            return;
        }
        if (InfrastructureLookupService::abusePredatesService($record, $this->case->effective_abuse_occurred_at)) {
            session()->flash('infra-error', 'Abuse predates this service\'s registration — the IP belonged to a different customer at the time. Not linked.');
            return;
        }

        $email = $record->userEmail;
        $clientId = $record->clientId;

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
        if ($this->case->target_ip && ! in_array($this->case->target_ip, $ipAddresses, true)) {
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
        $existingIdx = null;
        foreach ($services as $idx => $existing) {
            if ((string) ($existing['id'] ?? null) === (string) $record->serviceId) {
                $existingIdx = $idx;
                break;
            }
        }
        if ($existingIdx !== null) {
            $services[$existingIdx] = $serviceEntry;
        } else {
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
            \Illuminate\Support\Facades\Log::error('addCustomerFromInfrastructure failed', [
                'case_id' => $this->case->id,
                'attributes' => $attributes,
                'error' => $e->getMessage(),
            ]);
            session()->flash('infra-error', 'Could not save customer: ' . $e->getMessage());
            return;
        }

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::NoteAdded,
            'payload' => [
                'customer_id' => $customer->id,
                'customer_email' => $customer->email,
                'brand' => $brand->name,
                'client_id' => $clientId,
                'provider' => $record->rawProvider,
                'created' => $created,
            ],
            'note' => $created
                ? "Customer {$customer->email} created from infrastructure lookup and linked (brand: {$brand->name})"
                : "Customer {$customer->email} linked from infrastructure lookup (brand: {$brand->name})",
            'created_at' => now(),
        ]);

        session()->flash('infra-success', ($created ? 'Customer created and linked: ' : 'Customer linked: ') . $customer->email);
        $this->case->refresh();
    }

    public function assignBrand(string $brandId): void
    {
        $brand = Brand::find($brandId);
        if (! $brand) {
            return;
        }

        $oldBrand = $this->case->brand?->name ?? 'None';
        $this->case->update(['brand_id' => $brand->id]);

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::NoteAdded,
            'payload' => ['old_brand' => $oldBrand, 'new_brand' => $brand->name],
            'note' => "Brand changed from {$oldBrand} to {$brand->name}",
            'created_at' => now(),
        ]);

        $this->replyBrandId = $brand->id;
        $this->case->refresh();
    }

    public function unassignBrand(): void
    {
        $oldBrand = $this->case->brand?->name ?? 'None';
        $this->case->update(['brand_id' => null]);

        CaseAction::create([
            'case_id' => $this->case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::NoteAdded,
            'payload' => ['old_brand' => $oldBrand, 'new_brand' => null],
            'note' => "Brand removed (was {$oldBrand})",
            'created_at' => now(),
        ]);

        $this->case->refresh();
    }

    public function requestAiRescore(): void
    {
        RescoreCase::dispatch($this->case);
        session()->flash('ai-message', 'AI rescore job dispatched.');
    }

    public function requestAiSummary(): void
    {
        AnalyseEvidence::dispatch($this->case);
        session()->flash('ai-message', 'AI evidence analysis dispatched.');
    }

    public function requestAiReporterDraft(): void
    {
        DraftReporterReply::dispatch($this->case, 'status_update');
        session()->flash('ai-message', 'AI reporter reply draft dispatched.');
    }

    public function requestAiCustomerDraft(): void
    {
        DraftCustomerNotice::dispatch($this->case, $this->case->status->value);
        session()->flash('ai-message', 'AI customer notice draft dispatched.');
    }

    public function loadMoreReports(): void
    {
        $this->reportsPage++;
    }

    public function render()
    {
        $reports = $this->case->reports()
            ->with('reporter')
            ->latest()
            ->paginate($this->reportsPerPage, ['*'], 'reports_page', $this->reportsPage);
        $enrichment = $this->aggregateEnrichment($reports);

        $sentEmails = SentEmail::where('case_id', $this->case->id)->latest()->take(10)->get();

        // Build email conversation timeline
        $conversation = collect();

        // Add sent emails
        foreach ($sentEmails as $sent) {
            $conversation->push([
                'type' => 'sent',
                'date' => $sent->created_at,
                'from' => $sent->from_email ?? 'Abuse Desk',
                'to' => $sent->to_email,
                'subject' => $sent->subject,
                'body' => $sent->body_html ?? $sent->body_text ?? '',
                'status' => $sent->status,
                'transport' => $sent->metadata['transport'] ?? null,
            ]);
        }

        // Add email replies from case actions
        foreach ($this->case->actions()->where('action_type', ActionType::NoteAdded)->get() as $action) {
            if (($action->payload['type'] ?? '') === 'email_reply') {
                $conversation->push([
                    'type' => 'received',
                    'date' => $action->created_at,
                    'from' => $action->payload['from'] ?? 'Unknown',
                    'from_name' => $action->payload['from_name'] ?? '',
                    'subject' => $action->payload['subject'] ?? '',
                    'body' => $action->note ?? '',
                ]);
            }
        }

        // Add original report emails
        foreach ($this->case->reports()->where('source', 'email')->get() as $report) {
            $conversation->push([
                'type' => 'received',
                'date' => $report->reported_at ?? $report->created_at,
                'from' => $report->reporter?->email ?? 'Unknown Reporter',
                'from_name' => $report->reporter?->name ?? '',
                'subject' => $report->headers['subject'] ?? 'Abuse Report',
                'body' => $report->evidence ?? $report->raw_payload ?? '',
            ]);
        }

        // Sort by date
        $conversation = $conversation->sortBy('date')->values();

        // Resolve any provider-supplied customer once here so the Blade
        // view doesn't run lookup queries during render.
        $existingCustomer = null;
        if (is_array($this->infraData)) {
            $clientId = $this->infraData['client_id'] ?? null;
            $email = $this->infraData['user_email'] ?? null;
            $brandId = $this->infraData['brand_id'] ?? $this->case->brand_id;
            if ($clientId && $brandId) {
                $existingCustomer = Customer::where('external_id', (string) $clientId)
                    ->where('brand_id', $brandId)
                    ->first();
            }
            if (! $existingCustomer && $email) {
                $existingCustomer = Customer::where('email', $email)->first();
            }
        }

        $infraServicePredatesAbuse = false;
        if (is_array($this->infraData) && ! empty($this->infraData['service_id'])) {
            $record = $this->fetchServiceRecord();

            $infraServicePredatesAbuse = $record
                ? InfrastructureLookupService::abusePredatesService($record, $this->case->effective_abuse_occurred_at)
                : false;
        }

        // Aggregate attachments across every report linked to this case so
        // the agent has a single place to find evidence files.
        $attachments = collect();
        foreach ($this->case->reports()->whereNotNull('attachment_paths')->get() as $r) {
            foreach (($r->attachment_paths ?? []) as $idx => $path) {
                $attachments->push([
                    'report_id' => $r->id,
                    'index' => $idx,
                    'path' => $path,
                    'filename' => basename($path),
                    'reported_at' => $r->reported_at ?? $r->created_at,
                    'reporter' => $r->reporter?->email,
                ]);
            }
        }
        $attachments = $attachments->sortByDesc('reported_at')->values();

        return view('livewire.admin.case-detail', [
            'actions' => $this->case->actions()->latest('created_at')->take(50)->get(),
            'reports' => $reports,
            'takedownUrls' => $this->takedownCandidates(app(UrlTakedownService::class)),
            'hasMoreReports' => $reports->hasMorePages(),
            'attachments' => $attachments,
            'aiDrafts' => $this->case->actions()
                ->where('action_type', ActionType::AiDrafted)
                ->where(function ($q) {
                    $q->whereNull('payload')
                        ->orWhereNull('payload->dismissed')
                        ->orWhere('payload->dismissed', false);
                })
                ->latest('created_at')
                ->take(5)
                ->get(),
            'sentEmails' => $sentEmails,
            'statuses' => CaseStatus::cases(),
            'closeReasons' => [
                'not_correct' => 'Not correct',
                'false_positive' => 'False positive',
                'duplicate' => 'Duplicate',
                'no_action_needed' => 'No action needed',
                'marketing_or_spam_pitch' => 'Marketing / SEO pitch (not abuse)',
                'resolved_offline' => 'Resolved offline',
                'other' => 'Other',
            ],
            'enrichment' => $enrichment,
            'brands' => Brand::active()->get(),
            'conversation' => $conversation,
            'existingCustomer' => $existingCustomer,
            'infraServicePredatesAbuse' => $infraServicePredatesAbuse,
        ]);
    }

    protected function aggregateEnrichment($reports): array
    {
        $enrichment = [
            'abuseipdb' => null,
            'virustotal' => null,
            'shodan' => null,
            'dnsbl' => null,
            'threat_score' => null,
        ];

        foreach ($reports as $report) {
            $data = $report->enrichment ?? [];
            $ipIntel = $data['ip_intel'] ?? [];

            if (isset($ipIntel['abuseipdb']) && ! $enrichment['abuseipdb']) {
                $enrichment['abuseipdb'] = $ipIntel['abuseipdb'];
            }
            if (isset($ipIntel['virustotal']) && ! $enrichment['virustotal']) {
                $enrichment['virustotal'] = $ipIntel['virustotal'];
            }
            if (isset($ipIntel['shodan']) && ! $enrichment['shodan']) {
                $enrichment['shodan'] = $ipIntel['shodan'];
            }
            if (isset($ipIntel['dnsbl']) && ! $enrichment['dnsbl']) {
                $enrichment['dnsbl'] = $ipIntel['dnsbl'];
            }
            if (isset($ipIntel['threat_score']) && ! $enrichment['threat_score']) {
                $enrichment['threat_score'] = $ipIntel['threat_score'];
            }
        }

        return $enrichment;
    }
}
