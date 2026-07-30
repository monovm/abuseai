<?php

namespace Database\Seeders;

use App\Enums\AbuseType;
use App\Enums\ActionType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\CaseAction;
use App\Models\Customer;
use App\Models\Reporter;
use App\Models\SuspensionHistory;
use Illuminate\Database\Seeder;

class AbuseCaseSeeder extends Seeder
{
    public function run(): void
    {
        $reporters = Reporter::where('is_blocked', false)->get();
        $shadyHosting = Customer::where('email', 'admin@shadyhosting.example')->first();
        $webdevAgency = Customer::where('email', 'info@webdev-agency.example')->first();
        $legitCorp = Customer::where('email', 'support@legit-corp.example')->first();

        // --- Case 1: Critical spam case (suspended customer) ---
        $case1 = AbuseCase::create([
            'case_number' => 'ABU-2026-00001',
            'status' => CaseStatus::Actioned,
            'abuse_type' => AbuseType::Spam,
            'severity_score' => 88.5,
            'severity_level' => SeverityLevel::Critical,
            'target_ip' => '198.51.100.10',
            'customer_id' => $shadyHosting?->id,
            'report_count' => 12,
            'first_seen_at' => now()->subDays(7),
            'last_seen_at' => now()->subDays(1),
            'actioned_at' => now()->subDays(3),
            'ai_summary' => 'Multiple spam reports from trusted feeds targeting 198.51.100.10. Evidence shows bulk email sending with forged headers. Customer has prior history of spam violations.',
            'sla_deadline' => now()->subDays(6),
        ]);

        $this->createReports($case1, $reporters, 12, AbuseType::Spam, '198.51.100.10');
        $this->addAction($case1, ActionType::Opened, 'Case opened from AbuseIPDB feed');
        $this->addAction($case1, ActionType::AiScored, 'AI adjusted score to 88.5', ['adjusted_score' => 88.5]);
        $this->addAction($case1, ActionType::Suspended, 'Customer auto-suspended (score: 88.5)');
        $this->addAction($case1, ActionType::EmailSent, 'Suspension notice sent to customer');

        if ($shadyHosting) {
            SuspensionHistory::create([
                'customer_id' => $shadyHosting->id,
                'case_id' => $case1->id,
                'suspension_reason' => 'Auto-suspended: severity score 88.5 exceeds threshold 80',
                'suspended_at' => now()->subDays(3),
            ]);
        }

        // --- Case 2: Phishing investigation ---
        $case2 = AbuseCase::create([
            'case_number' => 'ABU-2026-00002',
            'status' => CaseStatus::Investigating,
            'abuse_type' => AbuseType::Phishing,
            'severity_score' => 62.3,
            'severity_level' => SeverityLevel::High,
            'target_ip' => '198.51.100.11',
            'target_domain' => 'login-secure-bank.example',
            'customer_id' => $shadyHosting?->id,
            'report_count' => 5,
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now()->subHours(6),
            'ai_summary' => 'Phishing page mimicking a banking login detected on 198.51.100.11. Domain registered 48 hours ago. Multiple reporter confirmations.',
            'sla_deadline' => now()->addHours(2),
        ]);

        $this->createReports($case2, $reporters, 5, AbuseType::Phishing, '198.51.100.11');
        $this->addAction($case2, ActionType::Opened, 'Case opened from form report');
        $this->addAction($case2, ActionType::Assigned, 'Assigned to abuse team');
        $this->addAction($case2, ActionType::AiScored, 'AI score: 62.3', ['adjusted_score' => 62.3]);

        // --- Case 3: DDoS - high severity, open ---
        $case3 = AbuseCase::create([
            'case_number' => 'ABU-2026-00003',
            'status' => CaseStatus::Open,
            'abuse_type' => AbuseType::Ddos,
            'severity_score' => 72.1,
            'severity_level' => SeverityLevel::High,
            'target_ip' => '198.51.100.20',
            'customer_id' => $webdevAgency?->id,
            'report_count' => 3,
            'first_seen_at' => now()->subHours(12),
            'last_seen_at' => now()->subHours(2),
            'ai_summary' => 'DDoS amplification traffic originating from 198.51.100.20. UDP reflection attack pattern detected in evidence.',
            'sla_deadline' => now()->addHours(4),
        ]);

        $this->createReports($case3, $reporters, 3, AbuseType::Ddos, '198.51.100.20');
        $this->addAction($case3, ActionType::Opened, 'Case opened from partner ISP API');

        // --- Case 4: Malware - resolved ---
        $case4 = AbuseCase::create([
            'case_number' => 'ABU-2026-00004',
            'status' => CaseStatus::Resolved,
            'abuse_type' => AbuseType::Malware,
            'severity_score' => 45.0,
            'severity_level' => SeverityLevel::Medium,
            'target_ip' => '198.51.100.21',
            'customer_id' => $webdevAgency?->id,
            'report_count' => 2,
            'first_seen_at' => now()->subDays(14),
            'last_seen_at' => now()->subDays(12),
            'resolved_at' => now()->subDays(10),
            'ai_summary' => 'Malware distribution via compromised WordPress site. Customer notified and remediated within 48 hours.',
            'sla_deadline' => now()->subDays(13),
        ]);

        $this->createReports($case4, $reporters, 2, AbuseType::Malware, '198.51.100.21');
        $this->addAction($case4, ActionType::Opened, 'Case opened from VirusTotal feed');
        $this->addAction($case4, ActionType::EmailSent, 'Warning sent to customer');
        $this->addAction($case4, ActionType::NoteAdded, 'Customer responded: WordPress plugin was compromised. Updated and cleaned.');
        $this->addAction($case4, ActionType::StatusChanged, 'Resolved — customer remediated', ['from' => 'investigating', 'to' => 'resolved']);

        // --- Case 5: Copyright - low severity ---
        $case5 = AbuseCase::create([
            'case_number' => 'ABU-2026-00005',
            'status' => CaseStatus::Open,
            'abuse_type' => AbuseType::Copyright,
            'severity_score' => 12.0,
            'severity_level' => SeverityLevel::Low,
            'target_ip' => '198.51.100.30',
            'target_domain' => 'downloads.legit-corp.example',
            'customer_id' => $legitCorp?->id,
            'report_count' => 1,
            'first_seen_at' => now()->subDays(1),
            'last_seen_at' => now()->subDays(1),
            'sla_deadline' => now()->addDays(2),
        ]);

        $this->createReports($case5, $reporters, 1, AbuseType::Copyright, '198.51.100.30');
        $this->addAction($case5, ActionType::Opened, 'Case opened from DMCA email report');

        // --- Case 6: Fraud - medium ---
        $case6 = AbuseCase::create([
            'case_number' => 'ABU-2026-00006',
            'status' => CaseStatus::Open,
            'abuse_type' => AbuseType::Fraud,
            'severity_score' => 38.5,
            'severity_level' => SeverityLevel::Medium,
            'target_ip' => '198.51.100.69',
            'report_count' => 4,
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now()->subHours(18),
            'ai_summary' => 'Fraudulent e-commerce site on shared hosting IP. Multiple consumer complaints about non-delivery of goods.',
            'sla_deadline' => now()->addHours(20),
        ]);

        $this->createReports($case6, $reporters, 4, AbuseType::Fraud, '198.51.100.69');
        $this->addAction($case6, ActionType::Opened, 'Case opened from web form reports');

        // --- Case 7: Spam - closed ---
        $case7 = AbuseCase::create([
            'case_number' => 'ABU-2026-00007',
            'status' => CaseStatus::Closed,
            'abuse_type' => AbuseType::Spam,
            'severity_score' => 15.0,
            'severity_level' => SeverityLevel::Low,
            'target_ip' => '198.51.100.81',
            'report_count' => 1,
            'first_seen_at' => now()->subDays(30),
            'last_seen_at' => now()->subDays(30),
            'resolved_at' => now()->subDays(28),
            'closed_at' => now()->subDays(25),
            'ai_summary' => 'Single spam report. Investigation found misconfigured mail relay. Customer fixed within 24 hours.',
            'sla_deadline' => now()->subDays(27),
        ]);

        $this->createReports($case7, $reporters, 1, AbuseType::Spam, '198.51.100.81');
        $this->addAction($case7, ActionType::Opened, 'Case opened');
        $this->addAction($case7, ActionType::StatusChanged, 'Closed — false positive / resolved', ['from' => 'resolved', 'to' => 'closed']);

        // --- Cases 8-15: Additional random open/investigating cases ---
        $targetIps = ['198.51.100.65', '198.51.100.66', '198.51.100.67', '198.51.100.71',
                       '198.51.100.72', '198.51.100.82', '198.51.100.83', '198.51.100.31'];
        $types = [AbuseType::Spam, AbuseType::Phishing, AbuseType::Spam, AbuseType::Malware,
                  AbuseType::Ddos, AbuseType::Spam, AbuseType::Fraud, AbuseType::Spam];

        for ($i = 0; $i < 8; $i++) {
            $type = $types[$i];
            $score = fake()->randomFloat(2, 5, 75);
            $reportCount = fake()->numberBetween(1, 8);

            $case = AbuseCase::create([
                'case_number' => 'ABU-2026-' . str_pad(8 + $i, 5, '0', STR_PAD_LEFT),
                'status' => fake()->randomElement([CaseStatus::Open, CaseStatus::Investigating]),
                'abuse_type' => $type,
                'severity_score' => $score,
                'severity_level' => SeverityLevel::fromScore($score),
                'target_ip' => $targetIps[$i],
                'report_count' => $reportCount,
                'first_seen_at' => now()->subDays(fake()->numberBetween(1, 14)),
                'last_seen_at' => now()->subHours(fake()->numberBetween(1, 48)),
                'sla_deadline' => now()->addHours(fake()->numberBetween(-12, 72)),
            ]);

            $this->createReports($case, $reporters, $reportCount, $type, $targetIps[$i]);
            $this->addAction($case, ActionType::Opened, 'Case opened');
        }
    }

    private function createReports(AbuseCase $case, $reporters, int $count, AbuseType $type, string $ip): void
    {
        $sources = ['feed', 'api', 'form', 'email'];
        $evidenceSamples = [
            'spam' => "Received unsolicited bulk email from {$ip}. Headers show forged sender. Content contains pharmaceutical spam links.",
            'phishing' => "Phishing page hosted at {$ip} impersonating a banking login. URL contains suspicious redirect parameters.",
            'malware' => "Malware binary served from {$ip}. SHA256: " . fake()->sha256() . ". Detected by multiple AV engines.",
            'ddos' => "UDP amplification traffic originating from {$ip}. Target: victim-server.example:53. Peak rate: 2.4 Gbps.",
            'copyright' => "Copyrighted material hosted on {$ip}. DMCA takedown notice attached. Content: unauthorized movie distribution.",
            'fraud' => "Fraudulent e-commerce site on {$ip}. Reports of payment collection with no product delivery.",
            'other' => "Suspicious activity detected from {$ip}. See attached logs for details.",
        ];

        for ($i = 0; $i < $count; $i++) {
            $reporter = $reporters->random();
            $evidence = $evidenceSamples[$type->value] ?? $evidenceSamples['other'];

            AbuseReport::create([
                'case_id' => $case->id,
                'reporter_id' => $reporter->id,
                'raw_payload' => $evidence,
                'source' => $sources[array_rand($sources)],
                'abuse_type' => $type,
                'target_ip' => $ip,
                'target_domain' => $case->target_domain,
                'evidence' => $evidence,
                'reported_at' => $case->first_seen_at->addHours($i * 2),
                'ai_classification' => [
                    'type' => $type->value,
                    'confidence' => fake()->randomFloat(2, 0.7, 0.99),
                    'iocs' => ['ips' => [$ip], 'domains' => [], 'urls' => []],
                    'summary' => "Classified as {$type->value} with high confidence.",
                ],
                'ai_noise_score' => fake()->randomFloat(2, 0.01, 0.3),
            ]);
        }
    }

    private function addAction(AbuseCase $case, ActionType $type, string $note, ?array $payload = null): void
    {
        static $offset = 0;
        $offset++;

        CaseAction::create([
            'case_id' => $case->id,
            'action_type' => $type,
            'payload' => $payload,
            'note' => $note,
            'created_at' => $case->first_seen_at->addMinutes($offset * 15),
        ]);
    }
}
