<?php

namespace App\Livewire\Admin;

use App\Enums\AbuseType;
use App\Enums\ActionType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\CaseAction;
use App\Models\Customer;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\CaseEngine\SeverityScorerService;
use Livewire\Component;

class CreateCase extends Component
{
    // Case fields
    public string $abuseType = 'spam';
    public string $targetIp = '';
    public string $targetDomain = '';
    public string $description = '';
    public string $evidence = '';
    public string $reporterName = '';
    public string $reporterEmail = '';

    // Optional
    public string $customerId = '';
    public string $severity = 'auto'; // auto or manual
    public ?float $manualScore = null;

    // IP lookup result
    public ?string $ipOwner = null;
    public bool $ipInInventory = false;

    public function updatedTargetIp(): void
    {
        $this->ipOwner = null;
        $this->ipInInventory = false;
        $this->customerId = '';

        if (! $this->targetIp || ! filter_var($this->targetIp, FILTER_VALIDATE_IP)) {
            return;
        }

        $ipRecord = IpAddress::findByIp($this->targetIp);

        if ($ipRecord) {
            $this->ipInInventory = $ipRecord->status === 'active';
            $this->ipOwner = $ipRecord->server_name ?? 'Unknown server';

            if ($ipRecord->customer_id) {
                $this->customerId = $ipRecord->customer_id;
            }
        }
    }

    public function save(SeverityScorerService $scorer): void
    {
        $this->validate([
            'abuseType' => ['required', 'string'],
            'description' => ['required', 'string', 'min:10'],
            'reporterName' => ['required', 'string', 'max:255'],
            'reporterEmail' => ['required', 'email', 'max:255'],
        ]);

        // Require at least one target
        if (empty($this->targetIp) && empty($this->targetDomain)) {
            $this->addError('targetIp', 'Provide at least a target IP or domain.');
            return;
        }

        // Find or create reporter as manual type
        $reporter = Reporter::firstOrCreate(
            ['email' => $this->reporterEmail],
            [
                'name' => $this->reporterName,
                'type' => 'manual',
            ],
        );
        $reporter->increment('report_count');

        // Create the case
        $case = AbuseCase::create([
            'case_number' => AbuseCase::generateCaseNumber(),
            'status' => CaseStatus::Open,
            'abuse_type' => $this->abuseType,
            'severity_score' => 0,
            'severity_level' => SeverityLevel::Low,
            'target_ip' => $this->targetIp ?: null,
            'target_domain' => $this->targetDomain ?: null,
            'customer_id' => $this->customerId ?: null,
            'report_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'sla_deadline' => now()->addHours(config('abusedesk.sla.low', 72)),
        ]);

        // Create the report
        AbuseReport::create([
            'case_id' => $case->id,
            'reporter_id' => $reporter->id,
            'raw_payload' => $this->description,
            'source' => 'manual',
            'abuse_type' => $this->abuseType,
            'target_ip' => $this->targetIp ?: null,
            'target_domain' => $this->targetDomain ?: null,
            'evidence' => $this->evidence ?: $this->description,
            'reported_at' => now(),
        ]);

        // Score the case
        if ($this->severity === 'manual' && $this->manualScore !== null) {
            $score = min(max((float) $this->manualScore, 0), 100);
            $case->update([
                'severity_score' => round($score, 2),
                'severity_level' => SeverityLevel::fromScore($score),
            ]);
        } else {
            $scorer->scoreAndUpdate($case);
        }

        // Refresh for new SLA based on severity
        $case->refresh();
        $hours = config("abusedesk.sla.{$case->severity_level->value}", 72);
        $case->update(['sla_deadline' => now()->addHours($hours)]);

        // Log the action
        CaseAction::create([
            'case_id' => $case->id,
            'actor_id' => auth()->id(),
            'action_type' => ActionType::Opened,
            'note' => 'Case created manually by ' . (auth()->user()?->name ?? 'admin'),
            'created_at' => now(),
        ]);

        session()->flash('success', "Case {$case->case_number} created successfully.");

        $this->redirect("/admin/cases/{$case->id}");
    }

    public function render()
    {
        return view('livewire.admin.create-case', [
            'abuseTypes' => AbuseType::cases(),
            'customers' => Customer::orderBy('email')->get(['id', 'email', 'company']),
        ]);
    }
}
