<?php

namespace App\Livewire\Admin;

use App\Concerns\AuthorizesAdmin;
use App\Events\ReportReceived;
use App\Models\Brand;
use App\Models\EmailConnection;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\Ingestion\MailboxClient;
use App\Services\Ingestion\ReportNormalizerService;
use App\Support\Text\Utf8;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class EmailInbox extends Component
{
    use AuthorizesAdmin;

    // Connection
    public ?string $connectionId = null;
    public ?string $connectionName = null;
    public ?string $brandId = null;
    public ?string $brandName = null;

    // Connection settings (mirror EmailConnection for the read-only header)
    public string $protocol = 'pop3';
    public string $host = '';
    public int $port = 995;
    public string $username = '';
    public string $password = '';
    public bool $ssl = true;

    // IMAP folders this connection is configured for, plus the one currently
    // selected in the UI. POP3 ignores both.
    public array $folders = ['INBOX'];
    public string $folder = 'INBOX';

    // State
    public array $emails = [];
    public ?array $selectedEmail = null;
    public bool $connected = false;
    public ?string $connectionError = null;
    public int $messageCount = 0;

    // All connections for switcher
    public array $allConnections = [];

    public function mount(): void
    {
        $connection = request()->query('connection');

        $this->allConnections = EmailConnection::with('brand')->active()->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'brand' => $c->brand?->name,
        ])->toArray();

        if ($connection) {
            $this->loadConnection($connection);
        } else {
            // Load first active connection, or fall back to config
            $firstConn = EmailConnection::active()->first();
            if ($firstConn) {
                $this->loadConnection($firstConn->id);
            } else {
                $this->host = config('mail.pop3.host', '');
                $this->port = (int) config('mail.pop3.port', 995);
                $this->username = config('mail.pop3.username', '');
                $this->password = config('mail.pop3.password', '');
                $this->ssl = (bool) config('mail.pop3.ssl', true);
            }
        }
    }

    public function loadConnection(string $id): void
    {
        $conn = EmailConnection::with('brand')->find($id);
        if (! $conn) {
            return;
        }

        $this->connectionId = $conn->id;
        $this->connectionName = $conn->name;
        $this->brandId = $conn->brand_id;
        $this->brandName = $conn->brand?->name;
        $this->protocol = $conn->protocol;
        $this->host = $conn->host;
        $this->port = $conn->port;
        $this->username = $conn->username;
        $this->password = $conn->password;
        $this->ssl = $conn->ssl;
        $this->folders = $conn->pollableFolders();
        $this->folder = $this->folders[0] ?? 'INBOX';

        // Reset state
        $this->emails = [];
        $this->selectedEmail = null;
        $this->connected = false;
        $this->connectionError = null;
    }

    public function switchConnection(string $id): void
    {
        $this->loadConnection($id);
    }

    public function switchFolder(string $folder): void
    {
        if (! in_array($folder, $this->folders, true)) {
            return;
        }
        $this->folder = $folder;
        $this->emails = [];
        $this->selectedEmail = null;
        $this->connected = false;
        $this->connectionError = null;
    }

    public function testConnection(MailboxClient $mailbox): void
    {
        $this->connectionError = null;

        $conn = $this->resolveConnection();
        if (! $conn) {
            $this->connectionError = 'No connection selected.';
            return;
        }

        $result = $mailbox->testConnection($conn, $this->folder);

        if ($result['success']) {
            $this->connected = true;
            $this->messageCount = $result['messages'];

            // Update connection record
            if ($this->connectionId) {
                EmailConnection::find($this->connectionId)?->update([
                    'last_polled_at' => now(),
                    'last_message_count' => $result['messages'],
                ]);
            }

            session()->flash('success', "Connected! {$result['messages']} messages in inbox (" . number_format(($result['size_bytes'] ?? 0) / 1024, 1) . " KB).");
        } else {
            $this->connected = false;
            $this->connectionError = $result['error'];
        }
    }

    public function fetchInbox(MailboxClient $mailbox): void
    {
        $this->connectionError = null;
        $this->emails = [];
        $this->selectedEmail = null;

        $conn = $this->resolveConnection();
        if (! $conn) {
            $this->connectionError = 'No connection selected.';
            return;
        }

        // Scrub to valid UTF-8 before it becomes Livewire state: a single
        // malformed byte in any subject/from/preview makes the next
        // Livewire update POST throw "Malformed UTF-8" and 500 the page.
        $this->emails = Utf8::cleanDeep($mailbox->listEmails($conn, $this->folder, 50));

        if (empty($this->emails)) {
            session()->flash('info', 'Inbox is empty or connection failed.');
        } else {
            // Mark emails that should be skipped
            foreach ($this->emails as &$emailItem) {
                $emailItem['skip_reason'] = $this->shouldSkipEmail($emailItem);
            }
            unset($emailItem);

            $this->connected = true;
            $this->messageCount = count($this->emails);
            $skipCount = count(array_filter($this->emails, fn ($e) => ! empty($e['skip_reason'])));
            $msg = "Fetched " . count($this->emails) . " emails.";
            if ($skipCount > 0) {
                $msg .= " ({$skipCount} replies/auto-replies will be skipped)";
            }
            session()->flash('success', $msg);
        }
    }

    public function viewEmail(int $messageId, MailboxClient $mailbox): void
    {
        $conn = $this->resolveConnection();
        if (! $conn) {
            session()->flash('error', 'No connection selected.');
            return;
        }

        // Scrub to valid UTF-8 before it becomes Livewire state — the raw
        // MIME body in particular routinely carries non-UTF-8 bytes that
        // would otherwise 500 the next update request.
        $this->selectedEmail = Utf8::cleanDeep($mailbox->fetchEmail($conn, $this->folder, $messageId));

        if (! $this->selectedEmail) {
            session()->flash('error', 'Failed to fetch email.');
        }
    }

    public function importAsReport(int $messageId, MailboxClient $mailbox, ReportNormalizerService $normalizer): void
    {
        $conn = $this->resolveConnection();
        if (! $conn) {
            session()->flash('error', 'No connection selected.');
            return;
        }

        $email = $mailbox->fetchEmail($conn, $this->folder, $messageId);

        if (! $email) {
            session()->flash('error', 'Failed to fetch email for import.');
            return;
        }

        // Store raw email + extracted binary attachments (PDFs, images, etc.)
        $disk = config('filesystems.default') ?: 'local';
        $emlPath = 'emails/' . date('Y/m') . '/' . uniqid() . '.eml';
        try {
            Storage::disk($disk)->put($emlPath, $email['raw']);
        } catch (\Throwable) {
            $emlPath = null;
        }

        $extractedPaths = $normalizer->storeEmailAttachments($email['raw'] ?? '', $disk);
        $allAttachmentPaths = array_values(array_filter(array_merge(
            $emlPath ? [$emlPath] : [],
            $extractedPaths,
        )));

        // Extract sender email — and look for an upstream reporter if this
        // email was forwarded to us. Attribution then goes to the upstream
        // party while we still record who forwarded it.
        $forwarderEmail = $this->extractEmail($email['from']);
        $forwarderName = $this->extractName($email['from']);

        $upstream = \App\Support\Email\ForwardedReporterDetector::detect($email);
        if ($upstream && strcasecmp($upstream['email'], $forwarderEmail) !== 0) {
            $fromEmail = $upstream['email'];
            $fromName = $upstream['name'] ?: $forwarderName;
            $forwardSource = $upstream['source'];
        } else {
            $fromEmail = $forwarderEmail;
            $fromName = $forwarderName;
            $forwardSource = null;
        }

        // Find or create reporter
        $reporter = Reporter::firstOrCreate(
            ['email' => $fromEmail],
            ['name' => $fromName ?: $fromEmail, 'type' => 'email'],
        );

        // Run a single AI pass over email text + attachment text (PDFs,
        // OCR'd images, .eml). Result is cached by content hash, so the
        // queued TriageReport jobs dispatched per-report will hit the
        // cache and not re-bill the API.
        $emailText = "Subject: {$email['subject']}\nFrom: {$email['from']}\n\n" .
            \App\Support\Email\EmailTextExtractor::allText($email);
        $attachText = ! empty($allAttachmentPaths)
            ? \App\Support\Attachments\AttachmentTextExtractor::fromPaths($allAttachmentPaths)
            : '';
        $contentForAi = $attachText !== ''
            ? $attachText . "\n\n=== Email Body ===\n" . $emailText
            : $emailText;

        $reporterContext = array_filter([
            'reporter_email' => $fromEmail,
            'reporter_name' => $fromName ?: null,
            'reporter_email_domain' => $fromEmail ? substr(strrchr($fromEmail, '@') ?: '', 1) : null,
            'reporter_source' => 'email',
        ]);

        $triage = app(\App\Services\AI\TriageAiService::class);
        $aiCombined = null;
        try {
            $aiCombined = $triage->triageAll($contentForAi, $reporterContext);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Email import AI pre-screen failed, proceeding without', [
                'error' => $e->getMessage(),
            ]);
        }
        $aiClassification = $aiCombined['classification'] ?? null;

        // Extract target IPs from email body (inventory hits) and merge
        // with AI-detected target IPs that are also in inventory — covers
        // IPs that appear only inside attached PDFs / screenshots.
        $ourIps = $this->extractOurIps(\App\Support\Email\EmailTextExtractor::allText($email));
        $aiIocs = $aiCombined['iocs'] ?? null;
        if ($aiIocs) {
            $aiTargetIps = $aiIocs['target_ips'] ?? [];
            $aiReporterIps = $aiIocs['reporter_ips'] ?? [];
            foreach ($aiTargetIps as $cand) {
                if (! $cand || in_array($cand, $aiReporterIps, true)) continue;
                if (in_array($cand, $ourIps, true)) continue;
                if (\App\Models\IpAddress::findByIp($cand)) {
                    $ourIps[] = $cand;
                }
            }
        }

        // Use AI classification for abuse type when confident; fall back
        // to keyword guess if the AI was unavailable.
        $abuseType = null;
        if ($aiClassification) {
            $confidence = $aiClassification['confidence'] ?? 0;
            $type = $aiClassification['type'] ?? null;
            $validTypes = ['spam', 'phishing', 'malware', 'ddos', 'csam', 'copyright', 'fraud',
                'law_enforcement', 'brute_force', 'intrusion', 'botnet', 'other'];
            if ($confidence >= 0.7 && in_array($type, $validTypes, true)) {
                $abuseType = $type;
            }
        }
        if (! $abuseType) {
            $abuseType = $this->guessAbuseType($email['subject'] ?? '', $email['body'] ?? '');
        }

        // Resolve brand from the email connection
        $reportBrandId = null;
        if ($this->connectionId) {
            $connection = EmailConnection::find($this->connectionId);
            $reportBrandId = $connection?->brand_id;
        }

        $caseCreator = app(\App\Services\CaseEngine\CaseCreatorService::class);
        $caseNumbers = [];

        // Create a report for each IP found (or one report with no IP if none found)
        $ipsToProcess = ! empty($ourIps) ? $ourIps : [null];

        foreach ($ipsToProcess as $targetIp) {
            $report = $normalizer->fromFeed([
                'abuse_type' => $abuseType,
                'target_ip' => $targetIp,
                'evidence' => $email['body'],
                'headers' => [
                    'subject' => $email['subject'],
                    'from' => $email['from'],
                    'to' => $email['to'],
                    'date' => $email['date'],
                    'message_id' => $email['message_id'],
                ],
                'reported_at' => $email['date'] ?: now(),
                'metadata' => array_filter([
                    'brand_id' => $reportBrandId,
                    'all_ips_in_email' => ! empty($ourIps) ? $ourIps : null,
                    'forwarded_by' => $forwardSource ? $forwarderEmail : null,
                    'forwarded_by_name' => $forwardSource && $forwarderName ? $forwarderName : null,
                    'forward_detection_source' => $forwardSource,
                ]),
            ], $reporter);

            if (! empty($allAttachmentPaths)) {
                $report->update(['attachment_paths' => $allAttachmentPaths]);
            }

            // Persist the combined AI result onto the report so the
            // queued TriageReport job's triageAll cache lookup will hit.
            if ($aiCombined) {
                $updates = [
                    'metadata' => array_merge($report->metadata ?? [], ['pre_screened' => true]),
                ];
                if ($aiClassification) {
                    $updates['ai_classification'] = $aiClassification;
                }
                if (isset($aiCombined['noise']['noise_score'])) {
                    $updates['ai_noise_score'] = $aiCombined['noise']['noise_score'];
                }
                if (! empty($aiCombined['iocs'])) {
                    $enrichment = $report->enrichment ?? [];
                    $enrichment['parsed_iocs'] = $aiCombined['iocs'];
                    $updates['enrichment'] = $enrichment;
                }
                $report->update($updates);

                if (! empty($aiCombined['iocs'])) {
                    \App\Support\AbuseTimestampParser::applyToReport($report, $aiCombined['iocs']);
                    \App\Support\ExternalCaseNumberCollector::applyToReport($report, $aiCombined['iocs']);
                }
            }

            // Create case directly (don't rely on queue)
            $case = $caseCreator->findOrCreateCase($report);

            if ($case) {
                $caseNumbers[] = $case->case_number;
            }

            // Always run the async pipeline so ai_classification, enrichment,
            // and reporter reputation get populated even when the report
            // wasn't case-linked. Dispatch directly rather than re-firing
            // ReportReceived — the listener would otherwise re-run AI
            // pre-screen and case creation we just performed.
            $this->dispatchAsyncProcessing($report);
        }

        if (! empty($caseNumbers)) {
            $caseList = implode(', ', $caseNumbers);
            $ipCount = count($ourIps);
            session()->flash('success', "Case(s) {$caseList} created from email ({$ipCount} IP(s)): {$email['subject']}");
        } else {
            $ipMsg = ! empty($ourIps) ? '' : ' (no IPs found in inventory)';
            session()->flash('success', "Email imported as report{$ipMsg}. Subject: {$email['subject']}");
        }

        // Remove from local list
        $this->emails = array_values(array_filter($this->emails, fn ($e) => $e['id'] !== $messageId));
        $this->selectedEmail = null;
    }

    public function importAllAsReports(MailboxClient $mailbox, ReportNormalizerService $normalizer): void
    {
        if (empty($this->emails)) {
            session()->flash('error', 'No emails to import. Fetch inbox first.');
            return;
        }

        $conn = $this->resolveConnection();
        if (! $conn) {
            session()->flash('error', 'No connection selected.');
            return;
        }

        $imported = 0;
        $failed = 0;

        foreach ($this->emails as $emailHeader) {
            $email = $mailbox->fetchEmail($conn, $this->folder, $emailHeader['id']);

            if (! $email) {
                $failed++;
                continue;
            }

            $forwarderEmail = $this->extractEmail($email['from']);
            $forwarderName = $this->extractName($email['from']);

            $upstream = \App\Support\Email\ForwardedReporterDetector::detect($email);
            if ($upstream && strcasecmp($upstream['email'], $forwarderEmail) !== 0) {
                $fromEmail = $upstream['email'];
                $fromName = $upstream['name'] ?: $forwarderName;
                $forwardSource = $upstream['source'];
            } else {
                $fromEmail = $forwarderEmail;
                $fromName = $forwarderName;
                $forwardSource = null;
            }

            $reporter = Reporter::firstOrCreate(
                ['email' => $fromEmail],
                ['name' => $fromName ?: $fromEmail, 'type' => 'email'],
            );

            $ourIps = $this->extractOurIps(\App\Support\Email\EmailTextExtractor::allText($email));
            $abuseType = $this->guessAbuseType($email['subject'] ?? '', $email['body'] ?? '');
            $caseCreator = app(\App\Services\CaseEngine\CaseCreatorService::class);

            // Create a report per IP (or one with no IP)
            $ipsToProcess = ! empty($ourIps) ? $ourIps : [null];

            foreach ($ipsToProcess as $targetIp) {
                $report = $normalizer->fromFeed([
                    'abuse_type' => $abuseType,
                    'target_ip' => $targetIp,
                    'evidence' => $email['body'],
                    'headers' => [
                        'subject' => $email['subject'],
                        'from' => $email['from'],
                        'date' => $email['date'],
                    ],
                    'reported_at' => $email['date'] ?: now(),
                    'metadata' => array_filter([
                        'all_ips_in_email' => ! empty($ourIps) ? $ourIps : null,
                        'forwarded_by' => $forwardSource ? $forwarderEmail : null,
                        'forwarded_by_name' => $forwardSource && $forwarderName ? $forwarderName : null,
                        'forward_detection_source' => $forwardSource,
                    ]),
                ], $reporter);

                $case = $caseCreator->findOrCreateCase($report);
                $imported++;

                $this->dispatchAsyncProcessing($report);
            }
        }

        session()->flash('success', "Imported {$imported} emails. {$failed} failed.");
        $this->emails = [];
        $this->selectedEmail = null;
    }

    public function deleteEmail(int $messageId, MailboxClient $mailbox): void
    {
        $this->authorizeAdmin();

        $conn = $this->resolveConnection();
        if (! $conn) {
            session()->flash('error', 'No connection selected.');
            return;
        }

        $result = $mailbox->deleteEmail($conn, $this->folder, $messageId);

        if ($result) {
            $this->emails = array_values(array_filter($this->emails, fn ($e) => $e['id'] !== $messageId));
            $this->selectedEmail = null;
            session()->flash('success', 'Email deleted from server.');
        } else {
            session()->flash('error', 'Failed to delete email.');
        }
    }

    public function closeEmail(): void
    {
        $this->selectedEmail = null;
    }

    /**
     * Kick off enrichment + AI triage for an imported report. Mirrors what
     * PollAbuseMailbox does after case creation, so reports created from the
     * UI behave identically to the auto-poller and don't sit at "Pending"
     * forever just because the manual path skipped the queue.
     */
    protected function dispatchAsyncProcessing(\App\Models\AbuseReport $report): void
    {
        // Bus::dispatch() instead of Job::dispatch() — the latter returns a
        // PendingDispatch whose __destruct does the push, and any exception
        // there is silently swallowed by PHP, so we'd never know if the push
        // failed. Bus::dispatch pushes inline and lets exceptions propagate.
        try {
            \Illuminate\Support\Facades\Bus::dispatch(new \App\Jobs\Processing\EnrichReport($report));
            \Illuminate\Support\Facades\Bus::dispatch(new \App\Jobs\AI\TriageReport($report));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Could not dispatch async jobs from email inbox import: ' . $e->getMessage(), [
                'report_id' => $report->id,
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * Resolve the EmailConnection backing the current page state. Falls back
     * to a transient in-memory object so the legacy "no connections, use
     * config()" code path still works for first-run setups.
     */
    protected function resolveConnection(): ?EmailConnection
    {
        if ($this->connectionId) {
            return EmailConnection::find($this->connectionId);
        }

        if (! $this->host) {
            return null;
        }

        // Transient connection — never saved. Lets us hit config-defined POP3
        // boxes before any EmailConnection rows exist.
        $conn = new EmailConnection([
            'protocol' => $this->protocol,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'ssl' => $this->ssl,
            'folders' => $this->folders,
        ]);
        $conn->password = $this->password;
        return $conn;
    }

    protected function extractEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return $matches[1];
        }
        if (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) {
            return trim($from);
        }
        return $from ?: 'unknown@email.abuseai';
    }

    protected function extractName(string $from): string
    {
        if (preg_match('/^"?([^"<]+)"?\s*</', $from, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    protected function extractFirstIp(string $text): ?string
    {
        $ips = $this->extractOurIps($text);

        return $ips[0] ?? null;
    }

    /**
     * Extract all IPs from text that are in our inventory (active).
     */
    protected function extractOurIps(string $text): array
    {
        $allIps = \App\Support\IpHelpers::extractIps($text);

        $ourIps = [];
        foreach ($allIps as $ip) {
            if (IpAddress::isOurs($ip)) {
                $ourIps[] = $ip;
            }
        }

        return $ourIps;
    }

    protected function extractAllIps(string $text): array
    {
        return \App\Support\IpHelpers::extractIps($text);
    }

    protected function guessAbuseType(string $subject, string $body): string
    {
        $text = strtolower($subject . ' ' . substr($body, 0, 3000));

        // Law enforcement / legal requests (check first — highest priority)
        if (str_contains($text, 'law enforcement') || str_contains($text, 'police')
            || str_contains($text, 'criminal') || str_contains($text, 'court order')
            || str_contains($text, 'subpoena') || str_contains($text, 'legal request')
            || str_contains($text, 'investigation') || str_contains($text, 'interpol')
            || str_contains($text, 'europol') || str_contains($text, 'prosecutor')
            || str_contains($text, 'subscriber information') || str_contains($text, 'provide information about')
            || str_contains($text, 'data request') || str_contains($text, 'ref. no.')
            || str_contains($text, 'cybercrime')) return 'law_enforcement';

        if (str_contains($text, 'csam') || str_contains($text, 'child')) return 'csam';
        if (str_contains($text, 'phish')) return 'phishing';
        if (str_contains($text, 'malware') || str_contains($text, 'virus') || str_contains($text, 'trojan') || str_contains($text, 'ransomware')) return 'malware';
        if (str_contains($text, 'botnet') || str_contains($text, 'c2') || str_contains($text, 'command and control') || str_contains($text, 'c&c')) return 'botnet';
        if (str_contains($text, 'ddos') || str_contains($text, 'denial of service') || str_contains($text, 'amplification') || str_contains($text, 'reflection')) return 'ddos';
        if (str_contains($text, 'brute force') || str_contains($text, 'brute-force') || str_contains($text, 'scanning') || str_contains($text, 'port scan')) return 'brute_force';
        if (str_contains($text, 'intrusion') || str_contains($text, 'hacking') || str_contains($text, 'unauthorized access') || str_contains($text, 'exploit')) return 'intrusion';
        if (str_contains($text, 'spam') || str_contains($text, 'uce') || str_contains($text, 'unsolicited') || str_contains($text, 'bulk mail')) return 'spam';
        if (str_contains($text, 'copyright') || str_contains($text, 'dmca') || str_contains($text, 'infringement') || str_contains($text, 'takedown')) return 'copyright';
        if (str_contains($text, 'fraud') || str_contains($text, 'scam') || str_contains($text, 'identity theft')) return 'fraud';

        return 'other';
    }

    /**
     * Check if an email should be skipped (reply to our own, auto-reply, loop).
     */
    protected function shouldSkipEmail(array $email): ?string
    {
        $from = strtolower($email['from'] ?? '');
        $subject = strtolower($email['subject'] ?? '');

        // Skip replies to our ACK emails
        if (preg_match('/re:\s*\[abu-\d{4}-\d{5}\]/i', $subject)) {
            return 'Reply to our ACK';
        }

        // Skip emails from our own addresses
        $ourEmails = \App\Models\Brand::pluck('from_email')->merge(
            EmailConnection::pluck('username')
        )->map(fn ($e) => strtolower($e))->unique();
        foreach ($ourEmails as $addr) {
            if (str_contains($from, $addr)) {
                return 'From our own address';
            }
        }

        // Skip auto-replies
        $autoIndicators = ['auto-reply', 'autoreply', 'out of office', 'out-of-office', 'delivery status', 'undeliverable', 'mailer-daemon', 'postmaster'];
        foreach ($autoIndicators as $indicator) {
            if (str_contains($subject, $indicator) || str_contains($from, $indicator)) {
                return 'Auto-reply';
            }
        }

        // Operator-defined drop tags. Scoped by the currently selected
        // connection's brand, plus global rules.
        $drop = \App\Models\EmailFilterRule::matchDrop(
            $email['subject'] ?? '',
            $email['body'] ?? '',
            $this->brandId,
        );
        if ($drop) {
            return "Drop rule: '{$drop->pattern}'";
        }

        return null;
    }

    public function render()
    {
        return view('livewire.admin.email-inbox');
    }
}
