<?php

namespace App\Console\Commands;

use App\Enums\ActionType;
use App\Models\CaseAction;
use App\Models\EmailConnection;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\AI\TriageAiService;
use App\Services\CaseEngine\CaseCreatorService;
use App\Services\Email\MandrillService;
use App\Services\Ingestion\MailboxClient;
use App\Services\Ingestion\ReportNormalizerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PollAbuseMailbox extends Command
{
    protected $signature = 'abuse:poll-mailbox
        {--connection= : Poll a specific connection ID}
        {--no-ack : Skip sending ACK reply emails}
        {--dry-run : Show what would be imported without creating cases}';

    protected $description = 'Poll all active email connections for abuse reports, create cases, and send ACK replies';

    public function handle(
        MailboxClient $mailbox,
        ReportNormalizerService $normalizer,
        CaseCreatorService $caseCreator,
        MandrillService $mandrill,
        TriageAiService $triage,
    ): int {
        $connectionId = $this->option('connection');

        if ($connectionId) {
            $connections = EmailConnection::where('id', $connectionId)->get();
        } else {
            $connections = EmailConnection::active()->where('auto_import', true)->with('brand')->get();
        }

        if ($connections->isEmpty()) {
            $this->info('No active email connections with auto-import enabled.');
            $this->info('Configure connections at /admin/brands or use --connection=ID');
            return self::SUCCESS;
        }

        $totalImported = 0;
        $totalCases = 0;
        $totalAcks = 0;

        foreach ($connections as $connection) {
            $folders = $connection->pollableFolders();
            $folderLabel = $connection->protocol === 'imap'
                ? ' [' . implode(', ', $folders) . ']'
                : '';
            $this->info("Polling: {$connection->name} ({$connection->username}@{$connection->host}){$folderLabel}...");

            $perConnectionTotal = 0;

            foreach ($folders as $folder) {
                try {
                    if ($connection->protocol === 'imap') {
                        $this->line("  Folder: {$folder}");
                    }

                    // processFolder holds one IMAP session for the entire
                    // folder, so 50 messages cost ~1 connect+login+select
                    // instead of 1 + 50 + 50. The handler decides per
                    // message whether to delete.
                    $count = $mailbox->processFolder($connection, $folder, 100, function (array $email, callable $delete) use (
                        $connection, $normalizer, $caseCreator, $mandrill, $triage,
                        &$totalImported, &$totalCases, &$totalAcks,
                    ) {
                        $linkedCase = $this->findLinkedCase($email);
                        if ($linkedCase) {
                            $this->addFollowUp($email, $linkedCase, $connection);
                            $this->line("    FOLLOW-UP on {$linkedCase->case_number}: {$email['from']}");
                            $delete();
                            $totalImported++;
                            return;
                        }

                        // No matching case — see if the reporter is following
                        // up on a still-uncased report (IP-not-in-inventory
                        // reports stay report-only). Surfaces as a separate
                        // bell notification so analysts can pick it up.
                        $linkedReport = $this->findLinkedReport($email);
                        if ($linkedReport) {
                            $this->addReportFollowUp($email, $linkedReport, $connection);
                            $this->line("    FOLLOW-UP on report {$linkedReport->id}: {$email['from']}");
                            $delete();
                            $totalImported++;
                            return;
                        }

                        $skipReason = $this->shouldSkipEmail($email, $connection);
                        if ($skipReason) {
                            $this->line("    SKIP: {$email['from']} — {$skipReason}");
                            $delete();
                            return;
                        }

                        if ($this->option('dry-run')) {
                            $this->line("    [DRY] From: {$email['from']} | Subject: {$email['subject']}");
                            return;
                        }

                        $result = $this->processEmail(
                            $email, $connection, $normalizer, $caseCreator, $mandrill, $triage,
                        );

                        if ($result['imported']) {
                            $totalImported++;
                        }
                        if ($result['case']) {
                            $totalCases++;
                        }
                        if ($result['ack_sent']) {
                            $totalAcks++;
                        }

                        $delete();
                    });

                    if ($count === 0) {
                        $this->info("    No emails.");
                    } else {
                        $this->info("    Processed {$count} emails.");
                        $perConnectionTotal += $count;
                    }
                } catch (\Throwable $e) {
                    Log::error("Mailbox poll failed for {$connection->name} folder {$folder}: " . $e->getMessage());
                    $this->error("    Error: {$e->getMessage()}");
                }
            }

            $connection->update([
                'last_polled_at' => now(),
                'last_message_count' => $perConnectionTotal,
            ]);
        }

        $this->newLine();
        $this->info("Done: {$totalImported} imported, {$totalCases} cases created, {$totalAcks} ACK replies sent.");

        return self::SUCCESS;
    }

    protected function processEmail(
        array $email,
        EmailConnection $connection,
        ReportNormalizerService $normalizer,
        CaseCreatorService $caseCreator,
        MandrillService $mandrill,
        TriageAiService $triage,
    ): array {
        $result = ['imported' => false, 'case' => null, 'ack_sent' => false];

        $forwarderEmail = $this->extractEmail($email['from']);
        $forwarderName = $this->extractName($email['from']);
        $subject = $email['subject'] ?? '(no subject)';
        $body = $email['body'] ?? '';

        // Sanitize for MySQL UTF-8 storage
        $body = $this->sanitizeUtf8($body);
        $subject = $this->sanitizeUtf8($subject);
        $forwarderName = $this->sanitizeUtf8($forwarderName);

        // Detect the upstream reporter when this is a forwarded complaint.
        // Falls back to the immediate sender when no forwarding signal is
        // found, so non-forwarded mail keeps its existing behaviour.
        $upstream = \App\Support\Email\ForwardedReporterDetector::detect($email);
        if ($upstream && strcasecmp($upstream['email'], $forwarderEmail) !== 0) {
            $fromEmail = $upstream['email'];
            $fromName = $upstream['name'] ?: $forwarderName;
            $forwardSource = $upstream['source'];
            $this->line("  Forwarded by {$forwarderEmail} — detected upstream reporter {$fromEmail} ({$forwardSource})");
        } else {
            $fromEmail = $forwarderEmail;
            $fromName = $forwarderName;
            $forwardSource = null;
        }

        $fromName = $this->sanitizeUtf8((string) $fromName);

        $this->line("  Processing: {$fromEmail} — {$subject}");

        // Store raw email + extract binary attachments BEFORE pre-screen
        // so the AI sees PDFs, images, and forwarded .eml content
        // alongside the body text. Without this the pre-screen would
        // mis-classify attachment-heavy reports as not_abuse and we'd
        // skip them entirely — which is exactly the bug that hit
        // form-submitted law-enforcement PDFs.
        $disk = config('filesystems.default') ?: 'local';
        $emlPath = 'emails/' . date('Y/m') . '/' . uniqid() . '.eml';
        try {
            Storage::disk($disk)->put($emlPath, $email['raw'] ?? '');
        } catch (\Throwable) {
            $emlPath = null;
        }

        $extractedPaths = $normalizer->storeEmailAttachments($email['raw'] ?? '', $disk);
        $allAttachmentPaths = array_values(array_filter(array_merge(
            $emlPath ? [$emlPath] : [],
            $extractedPaths,
        )));

        // Build the AI input: email text (subject/body/decoded text MIME
        // parts including forwarded .eml) plus extracted attachment text
        // (PDFs via smalot, images via vision OCR, etc.).
        $emailText = "Subject: {$subject}\nFrom: {$email['from']}\n\n" .
            \App\Support\Email\EmailTextExtractor::allText($email);
        $attachText = ! empty($allAttachmentPaths)
            ? \App\Support\Attachments\AttachmentTextExtractor::fromPaths($allAttachmentPaths)
            : '';
        $contentForAi = $attachText !== ''
            ? $attachText . "\n\n=== Email Body ===\n" . $emailText
            : $emailText;

        // --- AI Pre-Screening: classify + noise + IOCs in ONE call ---
        // Rate limit: max 20 sync AI calls per poll run to avoid API overload.
        // The combined call's result is cached by content hash, so the
        // queued TriageReport job will hit the cache and incur zero
        // additional API tokens for the same email.
        static $aiCallCount = 0;
        $maxAiCallsPerRun = 20;

        $aiClassification = null;
        $aiCombined = null;
        $reporterContext = array_filter([
            'reporter_email' => $fromEmail,
            'reporter_name' => $fromName ?: null,
            'reporter_email_domain' => $fromEmail ? substr(strrchr($fromEmail, '@') ?: '', 1) : null,
            'reporter_source' => 'email',
        ]);

        if ($aiCallCount < $maxAiCallsPerRun) {
            try {
                $aiCombined = $triage->triageAll($contentForAi, $reporterContext);
                $aiClassification = $aiCombined['classification'] ?? null;
                $aiCallCount++;
            } catch (\Throwable $e) {
                Log::warning('AI pre-screening failed, proceeding with manual classification', [
                    'from' => $fromEmail,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $this->line("    AI rate limit reached ({$maxAiCallsPerRun} calls) — using keyword classification");
        }

        if ($aiClassification) {
            $isNotAbuse = ($aiClassification['type'] ?? '') === 'not_abuse'
                || ($aiClassification['is_abuse_report'] ?? true) === false;

            if ($isNotAbuse) {
                $this->line("    AI SKIP: Not an abuse report — " . ($aiClassification['summary'] ?? 'no summary'));
                Log::info('Email skipped by AI pre-screening: not abuse', [
                    'from' => $fromEmail,
                    'subject' => $subject,
                    'ai_type' => $aiClassification['type'] ?? null,
                    'ai_summary' => $aiClassification['summary'] ?? null,
                    'ai_confidence' => $aiClassification['confidence'] ?? null,
                ]);

                return $result;
            }
        }

        // (Raw email + binary attachments were already stored above so the
        // pre-screen could read them; $allAttachmentPaths is reused below.)

        // Find or create reporter
        $reporter = Reporter::firstOrCreate(
            ['email' => $fromEmail],
            ['name' => $fromName ?: $fromEmail, 'type' => 'email'],
        );
        $reporter->increment('report_count');

        // Use AI classification type if available and confident, otherwise guess
        $abuseType = $this->resolveAbuseType($aiClassification, $subject, $body);

        // Extract target IP. First check the AI's IOC extraction (which now
        // sees attachment text including OCR'd images and PDFs), then fall
        // back to keyword scanning of email text. Only IPs in our inventory
        // are kept; the splitter handles spawning siblings for extras later.
        $targetIp = null;
        $aiIocs = $aiCombined['iocs'] ?? null;
        if ($aiIocs) {
            $aiTargetIps = $aiIocs['target_ips'] ?? [];
            $aiReporterIps = $aiIocs['reporter_ips'] ?? [];
            foreach ($aiTargetIps as $candidateIp) {
                if (! $candidateIp || in_array($candidateIp, $aiReporterIps, true)) {
                    continue;
                }
                $rec = \App\Models\IpAddress::findByIp($candidateIp);
                if ($rec) {
                    $targetIp = $candidateIp;
                    break;
                }
            }
        }
        if (! $targetIp) {
            $targetIp = $this->findOurIp(
                \App\Support\Email\EmailTextExtractor::allText($email)
            );
        }

        // Create the report
        $report = $normalizer->fromFeed([
            'abuse_type' => $abuseType,
            'target_ip' => $targetIp,
            'evidence' => $body,
            'headers' => [
                'subject' => $subject,
                'from' => $email['from'],
                'to' => $email['to'] ?? '',
                'date' => $email['date'] ?? '',
                'message_id' => $email['message_id'] ?? '',
            ],
            'reported_at' => $email['date'] ?: now(),
            // Best brand signal: the inbox this email landed in. Beats
            // Virtualizor-hostname guessing when the target IP is unknown
            // or not ours (e.g. forwarded CERT/M247 notices).
            'metadata' => array_filter([
                'brand_id' => $connection->brand_id,
                'received_via_connection_id' => $connection->id,
                'forwarded_by' => $forwardSource ? $forwarderEmail : null,
                'forwarded_by_name' => $forwardSource && $forwarderName ? $forwarderName : null,
                'forward_detection_source' => $forwardSource,
            ]),
        ], $reporter);

        if (! empty($allAttachmentPaths)) {
            $report->update(['attachment_paths' => $allAttachmentPaths]);
        }

        // Persist the combined AI result onto the report so the queued
        // TriageReport job's triageAll cache lookup will find a hit and
        // not re-bill the API. Same for noise + IOC enrichment.
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

            // Stamp the actual abuse-occurrence time from extracted
            // incident timestamps. Propagates to the case once linked.
            if (! empty($aiCombined['iocs'])) {
                \App\Support\AbuseTimestampParser::applyToReport($report, $aiCombined['iocs']);
                \App\Support\ExternalCaseNumberCollector::applyToReport($report, $aiCombined['iocs']);
            }
        }

        $result['imported'] = true;

        // Create case directly
        $case = $caseCreator->findOrCreateCase($report);
        $result['case'] = $case;

        if ($case) {
            $this->info("    Case {$case->case_number} created/linked.");

            // Send ACK reply if not disabled
            if (! $this->option('no-ack') && $mandrill->isConfigured()) {
                // ACK always goes to the forwarder — they're the one who
                // contacted us. The upstream reporter is the attribution,
                // not the reply destination.
                $ackSent = $this->sendAckReply(
                    $forwarderEmail, $forwarderName, $case, $connection, $mandrill,
                    $email['message_id'] ?? null,
                    $subject ?: ($email['subject'] ?? null),
                );
                $result['ack_sent'] = $ackSent;
            }
        } else {
            $this->warn("    No case created (IP not in inventory).");
        }

        // Dispatch async pipeline. The synchronous pre-screen above caps at
        // 20 AI calls per poll run, so any report past that quota — plus any
        // where pre-screen errored or returned null — needs the full TriageReport
        // job to populate ai_classification. Without this dispatch, mailbox-
        // ingested reports show "Pending" forever even though webhook/API
        // ingestion (HandleReportReceived) runs them. EnrichReport handles
        // GeoIP/ASN/WHOIS/threat intel.
        //
        // Use the Bus facade rather than `Job::dispatch($report)` because the
        // latter returns a PendingDispatch whose __destruct() is what actually
        // pushes to Redis — and exceptions thrown from a destructor are
        // silently swallowed by PHP. We were losing every push from this loop
        // that way: tinker dispatches worked, the auto-poll's didn't, and no
        // log line fired because the catch block below never saw the throw.
        // Bus::dispatch() pushes inline and lets exceptions propagate to our
        // try/catch.
        try {
            \Illuminate\Support\Facades\Bus::dispatch(new \App\Jobs\Processing\EnrichReport($report));
            \Illuminate\Support\Facades\Bus::dispatch(new \App\Jobs\AI\TriageReport($report));
            Log::info('Mailbox poller dispatched async jobs', [
                'report_id' => $report->id,
            ]);
        } catch (\Throwable $e) {
            // Queue not available — report is created, case is linked. Operators
            // can re-run AI triage manually from the report row.
            Log::warning('Could not dispatch async jobs from mailbox poller: ' . $e->getMessage(), [
                'report_id' => $report->id,
                'exception' => get_class($e),
            ]);
        }

        return $result;
    }

    protected function sendAckReply(
        string $toEmail,
        string $toName,
        $case,
        EmailConnection $connection,
        MandrillService $mandrill,
        ?string $inReplyTo,
        ?string $originalSubject = null,
    ): bool {
        $brand = $connection->brand;

        // Thread the ACK as a reply to the reporter's original email. Gmail
        // and most clients thread primarily by Subject — In-Reply-To alone
        // isn't enough if the subject differs, which is why a custom branded
        // subject like "[ABU-…] Report Received" spawns a new conversation
        // even with the right headers. Mirror SuspendCustomer::threadAnchor
        // and use "Re: {original}", falling back to the branded subject only
        // when no original subject is available.
        $original = $originalSubject ?: ($case->reports()->oldest()->first()?->headers['subject'] ?? null);
        $subject = $original
            ? (stripos(ltrim($original), 'Re:') === 0 ? $original : "Re: {$original}")
            : "Re: [{$case->case_number}] Report Received — {$case->abuse_type->label()}";

        $body = "<p>Dear " . e($toName ?: 'Reporter') . ",</p>"
            . "<p>Thank you for your abuse report. We have opened case <strong>{$case->case_number}</strong> "
            . "and our team is now investigating.</p>"
            . "<p><strong>Case Details:</strong></p>"
            . "<ul>"
            . "<li>Case Number: {$case->case_number}</li>"
            . "<li>Type: {$case->abuse_type->label()}</li>"
            . ($case->target_ip ? "<li>Target IP: {$case->target_ip}</li>" : "")
            . "</ul>"
            . "<p>We will keep you informed of any updates. If you have additional evidence "
            . "or information, please reply to this email referencing the case number above.</p>";

        try {
            $sentEmail = $mandrill->send(
                toEmail: $toEmail,
                toName: $toName,
                subject: $subject,
                htmlBody: $body,
                brand: $brand,
                inReplyTo: $inReplyTo,
                caseId: $case->id,
            );

            // Log the action on the case
            CaseAction::create([
                'case_id' => $case->id,
                'action_type' => ActionType::EmailSent,
                'payload' => [
                    'type' => 'auto_ack',
                    'to' => $toEmail,
                    'subject' => $subject,
                    'brand' => $brand?->name ?? 'default',
                    'status' => $sentEmail->status,
                ],
                'note' => "Auto ACK sent to {$toEmail}",
                'created_at' => now(),
            ]);

            $this->info("    ACK sent to {$toEmail}");
            return true;
        } catch (\Throwable $e) {
            Log::warning("Failed to send ACK to {$toEmail}: " . $e->getMessage());
            $this->warn("    ACK failed: {$e->getMessage()}");
            return false;
        }
    }

    protected function findOurIp(string $text, ?string $rawEmail = null): ?string
    {
        // Callers should pass pre-merged searchable text via
        // EmailTextExtractor::allText(). The legacy second arg is appended
        // for callers that still pass the raw RFC822 separately.
        $searchText = $text;
        if ($rawEmail) {
            $searchText .= "\n" . $rawEmail;
        }

        $allIps = \App\Support\IpHelpers::extractIps($searchText);

        // Only return IPs that are in our inventory — don't fall back to random public IPs
        // Falling back to any public IP causes false positives (e.g. sender IPs from SpamAssassin)
        foreach ($allIps as $ip) {
            if (IpAddress::isOurs($ip)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * Check if an email should be skipped (loop prevention, auto-replies, own emails).
     * Returns skip reason string, or null if should be processed.
     */
    protected function shouldSkipEmail(array $email, EmailConnection $connection): ?string
    {
        $from = strtolower($email['from'] ?? '');
        $subject = $email['subject'] ?? '';
        $headers = $email['headers'] ?? [];
        $subjectLower = strtolower($subject);

        // 1. Skip emails FROM our own addresses (loop prevention — we sent this)
        $ourAddresses = $this->getOurEmailAddresses($connection);
        foreach ($ourAddresses as $addr) {
            if (str_contains($from, strtolower($addr))) {
                return "From our own address: {$addr}";
            }
        }

        // 2. Skip auto-replies and out-of-office (not actionable)
        $autoReplyIndicators = [
            'auto-reply', 'autoreply', 'automatic reply',
            'out of office', 'out-of-office',
            'on vacation', 'currently unavailable',
            'delivery status notification', 'delivery failure',
            'undeliverable', 'mail delivery failed',
            'mailer-daemon', 'postmaster',
        ];
        foreach ($autoReplyIndicators as $indicator) {
            if (str_contains($subjectLower, $indicator) || str_contains($from, $indicator)) {
                return "Auto-reply/OOO: {$indicator}";
            }
        }

        // 3. Skip if Auto-Submitted header (RFC 3834)
        $autoSubmitted = strtolower($headers['auto-submitted'] ?? $headers['auto_submitted'] ?? '');
        if ($autoSubmitted && $autoSubmitted !== 'no') {
            return "Auto-Submitted header: {$autoSubmitted}";
        }

        // 4. Skip if Precedence: bulk/junk/auto_reply
        $precedence = strtolower($headers['precedence'] ?? '');
        if (in_array($precedence, ['bulk', 'junk', 'auto_reply'])) {
            return "Precedence header: {$precedence}";
        }

        // 5. Skip if body is essentially just a quote of our own ACK (no new content)
        $bodyLower = strtolower($email['body'] ?? '');
        if ($this->isOnlyQuotedAck($bodyLower)) {
            return "Only contains quoted ACK — no new content";
        }

        // 5b. Skip if email looks like a customer support inquiry (not an abuse report)
        if ($this->isCustomerInquiry($subjectLower, $bodyLower)) {
            return "Customer inquiry / support request — not an abuse report";
        }

        // 5c. Operator-defined drop tags (subject/body keywords). Matches
        // global rules and any rule scoped to this connection's brand.
        $drop = \App\Models\EmailFilterRule::matchDrop(
            $subject,
            $email['body'] ?? '',
            $connection->brand_id,
        );
        if ($drop) {
            return "Drop rule matched ({$drop->match_field}: '{$drop->pattern}')";
        }

        // 6. Skip if already imported (check by message_id)
        $messageId = $email['message_id'] ?? '';
        if ($messageId) {
            $exists = \App\Models\AbuseReport::whereJsonContains('headers->message_id', $messageId)->exists();
            if ($exists) {
                return "Already imported (message_id: {$messageId})";
            }
        }

        return null;
    }

    /**
     * Detect customer support inquiries / general correspondence that are not abuse reports.
     * These are emails from customers asking about services, policies, refunds, etc.
     */
    protected function isCustomerInquiry(string $subjectLower, string $bodyLower): bool
    {
        // Positive signals: phrases that indicate a support/inquiry email
        $inquiryPhrases = [
            'i would like to request',
            'i would appreciate',
            'i hope you can understand',
            'thank you for your support',
            'thank you for your clarification',
            'thank you for your response',
            'thank you for your assistance',
            'please assist me',
            'finding a workable solution',
            'complies with your policies',
            'i understand your policy',
            'additional verification',
            'your refund policy',
            'request your guidance',
            'how can i',
            'could you please help',
            'i need help with',
            'looking forward to hearing',
            'awaiting your response',
        ];

        $inquiryScore = 0;
        foreach ($inquiryPhrases as $phrase) {
            if (str_contains($bodyLower, $phrase)) {
                $inquiryScore++;
            }
        }

        // Negative signals: phrases that indicate actual abuse content
        $abuseIndicators = [
            'abuse report', 'spam', 'phishing', 'malware', 'ddos', 'botnet',
            'intrusion', 'brute force', 'port scan', 'exploit', 'virus',
            'ransomware', 'copyright infringement', 'dmca', 'csam',
            'unsolicited', 'bulk mail', 'complaint',
            'reported ip', 'offending ip', 'attacking ip',
        ];

        $hasAbuseContent = false;
        foreach ($abuseIndicators as $indicator) {
            if (str_contains($bodyLower, $indicator)) {
                $hasAbuseContent = true;
                break;
            }
        }

        // If multiple inquiry phrases match and no abuse indicators, it's likely a support email
        return $inquiryScore >= 2 && ! $hasAbuseContent;
    }

    /**
     * Check if email body only contains our own quoted ACK email with no meaningful new content.
     */
    protected function isOnlyQuotedAck(string $bodyLower): bool
    {
        // Must contain our ACK template text
        $hasOurAck = str_contains($bodyLower, 'thank you for your abuse report')
            || str_contains($bodyLower, 'our team is now investigating')
            || str_contains($bodyLower, 'we will keep you informed');

        if (! $hasOurAck) {
            return false;
        }

        // Strip the quoted part and check if anything meaningful remains
        // Remove common ISP auto-forward templates
        $stripped = $bodyLower;

        // Remove common ISP wrapper phrases
        $wrapperPhrases = [
            'we have received the below abuse message',
            'please deal with them as soon as possible',
            'confirm if possible in writing that it was resolved',
            'we would except a shift action',
            'many thanks for your cooperation',
            'this has been forwarded to the matching client',
            'please let us know if the issue was not resolved',
            'dear client',
            'abuse team',
            'ticket id',
            'ticket url',
            'status: answered',
            'status: open',
            'regards',
            'kind regards',
            'best regards',
        ];

        foreach ($wrapperPhrases as $phrase) {
            $stripped = str_replace($phrase, '', $stripped);
        }

        // Remove our own ACK content
        $ackPhrases = [
            'thank you for your abuse report',
            'our team is now investigating',
            'we will keep you informed of any updates',
            'if you have additional evidence',
            'please reply to this email referencing the case number',
            'case details:',
            'case number:',
            'type:',
            'target ip:',
            'report received',
        ];

        foreach ($ackPhrases as $phrase) {
            $stripped = str_replace($phrase, '', $stripped);
        }

        // Remove case numbers, IPs (v4 + v6), URLs, email addresses, punctuation, whitespace
        $stripped = preg_replace('/abu-\d{4}-\d{5}/', '', $stripped);
        foreach (\App\Support\IpHelpers::extractIps($stripped) as $ip) {
            $stripped = str_replace($ip, '', $stripped);
        }
        $stripped = preg_replace('/https?:\/\/\S+/', '', $stripped);
        $stripped = preg_replace('/[\w.-]+@[\w.-]+/', '', $stripped);
        $stripped = preg_replace('/[^a-z]/', '', $stripped);

        // If less than 30 meaningful characters remain, it's just a quoted ACK
        return strlen($stripped) < 30;
    }

    /**
     * Get all email addresses that belong to us (to detect our own emails).
     */
    protected function getOurEmailAddresses(EmailConnection $connection): array
    {
        $addresses = [];

        // Current connection username
        $addresses[] = $connection->username;

        // All connection usernames
        $allConnections = EmailConnection::pluck('username')->toArray();
        $addresses = array_merge($addresses, $allConnections);

        // All brand from_email addresses
        $brandEmails = \App\Models\Brand::pluck('from_email')->toArray();
        $addresses = array_merge($addresses, $brandEmails);

        // All brand reply_to addresses
        $replyTos = \App\Models\Brand::whereNotNull('reply_to_email')->pluck('reply_to_email')->toArray();
        $addresses = array_merge($addresses, $replyTos);

        return array_unique(array_filter($addresses));
    }

    /**
     * Check if email is a reply to an existing case.
     * Searches subject, body, headers, and quoted content.
     * Returns the linked AbuseCase or null.
     */
    protected function findLinkedCase(array $email): ?\App\Models\AbuseCase
    {
        $subject = $email['subject'] ?? '';
        $body = $email['body'] ?? '';
        $allText = $subject . ' ' . $body;

        // 1. Check for our case number anywhere: subject or body
        if (preg_match_all('/ABU-\d{4}-\d{5}/', $allText, $matches)) {
            foreach (array_unique($matches[0]) as $caseNumber) {
                $case = \App\Models\AbuseCase::where('case_number', $caseNumber)->first();
                if ($case) {
                    return $case;
                }
            }
        }

        // 2. Check In-Reply-To / References headers against sent_emails
        foreach (['in_reply_to', 'in-reply-to', 'references'] as $headerKey) {
            $headerVal = $email['headers'][$headerKey] ?? '';
            if ($headerVal) {
                $sentEmail = \App\Models\SentEmail::where('message_id', $headerVal)->first();
                if ($sentEmail?->case_id) {
                    return \App\Models\AbuseCase::find($sentEmail->case_id);
                }
            }
        }

        // 3. Detect quoted ACK email content (our template phrases in body)
        $bodyLower = strtolower($body);
        if (str_contains($bodyLower, 'thank you for your abuse report. we have opened case')
            || str_contains($bodyLower, 'our team is now investigating')
            || str_contains($bodyLower, 'we will keep you informed of any updates')) {
            // Our ACK was quoted — extract case number from it
            if (preg_match('/case\s+(?:number:?\s*)?(?:<[^>]*>)?\s*(ABU-\d{4}-\d{5})/i', $body, $m)) {
                $case = \App\Models\AbuseCase::where('case_number', $m[1])->first();
                if ($case) {
                    return $case;
                }
            }
        }

        // 4. Check if email quotes a previous email that references a known target IP + open case.
        // Search subject, body, and decoded attachments — IPs are often in the subject line.
        $ips = \App\Support\IpHelpers::extractIps(
            \App\Support\Email\EmailTextExtractor::allText($email)
        );
        if (! empty($ips)) {
            $case = \App\Models\AbuseCase::whereIn('target_ip', $ips)
                ->whereNotIn('status', ['closed', 'resolved'])
                ->latest()
                ->first();
            if ($case) {
                // Only link if the body also contains ISP auto-forwarding patterns
                if (str_contains($bodyLower, 'we have received the below abuse')
                    || str_contains($bodyLower, 'has been forwarded')
                    || str_contains($bodyLower, 'please deal with')
                    || str_contains($bodyLower, 'ticket id')
                    || str_contains($bodyLower, 'this is a confirmation')
                    || str_contains($bodyLower, 'your report')
                    || str_contains($bodyLower, 'case number')
                    || str_contains($bodyLower, 'we are investigating')
                    || preg_match('/re:\s*\[/i', $subject)) {
                    return $case;
                }
            }
        }

        return null;
    }

    /**
     * Add a reply email as a follow-up note/action on an existing case.
     */
    protected function addFollowUp(array $email, \App\Models\AbuseCase $case, EmailConnection $connection): void
    {
        $fromEmail = $this->extractEmail($email['from']);
        $fromName = $this->extractName($email['from']);
        $subject = $this->sanitizeUtf8($email['subject'] ?? '');
        $body = $this->sanitizeUtf8($email['body'] ?? '');

        // Store raw email
        $emlPath = 'emails/' . date('Y/m') . '/' . uniqid() . '.eml';
        try {
            \Illuminate\Support\Facades\Storage::disk('local')->put($emlPath, $email['raw'] ?? '');
        } catch (\Throwable) {
        }

        // Add as a case action (note with the reply content)
        CaseAction::create([
            'case_id' => $case->id,
            'action_type' => ActionType::NoteAdded,
            'payload' => [
                'type' => 'email_reply',
                'from' => $fromEmail,
                'from_name' => $fromName,
                'subject' => $subject,
                'message_id' => $email['message_id'] ?? null,
                'attachment' => $emlPath,
            ],
            'note' => "Email reply from {$fromName} <{$fromEmail}>:\n\n{$body}",
            'created_at' => now(),
        ]);

        // Update case last_seen_at
        $case->update(['last_seen_at' => now()]);

        \App\Models\InboxNotification::create([
            'type' => \App\Models\InboxNotification::TYPE_CASE_FOLLOWUP,
            'case_id' => $case->id,
            'title' => "Reporter follow-up on {$case->case_number}",
            'excerpt' => trim(($subject ? "{$subject} — " : '') . mb_substr($body, 0, 200)),
            'meta' => [
                'from' => $fromEmail,
                'from_name' => $fromName,
                'message_id' => $email['message_id'] ?? null,
            ],
        ]);

        Log::info("Follow-up added to case {$case->case_number}", [
            'from' => $fromEmail,
            'subject' => $subject,
        ]);
    }

    /**
     * Find an existing case-less abuse report this email is a follow-up to.
     * Conservative: requires the inbound mail to look like a reply/forward
     * (subject Re:/Fwd: or In-Reply-To match against a sent ACK for this
     * report's case-less parent) AND to come from the same reporter we
     * already have on file. Window is 30 days so abandoned threads don't
     * keep matching forever.
     */
    protected function findLinkedReport(array $email): ?\App\Models\AbuseReport
    {
        $fromEmail = strtolower($this->extractEmail($email['from'] ?? ''));
        if (! $fromEmail || ! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $subject = (string) ($email['subject'] ?? '');
        $isReplyLike = (bool) preg_match('/^(re|fwd?|aw|wg|tr|rv):/i', trim($subject));
        if (! $isReplyLike) {
            return null;
        }

        $reporter = \App\Models\Reporter::where('email', $fromEmail)->first();
        if (! $reporter) {
            return null;
        }

        // Most recent case-less report from this reporter in the last 30
        // days — covers "I sent you a report about 1.2.3.4, here's more."
        return \App\Models\AbuseReport::where('reporter_id', $reporter->id)
            ->whereNull('case_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()
            ->first();
    }

    /**
     * Attach an inbound follow-up to an existing case-less report — stamp
     * the new content into the report's metadata and raise a bell
     * notification so an analyst can pick it up.
     */
    protected function addReportFollowUp(array $email, \App\Models\AbuseReport $report, EmailConnection $connection): void
    {
        $fromEmail = $this->extractEmail($email['from']);
        $fromName = $this->extractName($email['from']);
        $subject = $this->sanitizeUtf8($email['subject'] ?? '');
        $body = $this->sanitizeUtf8($email['body'] ?? '');

        $emlPath = 'emails/' . date('Y/m') . '/' . uniqid() . '.eml';
        try {
            \Illuminate\Support\Facades\Storage::disk('local')->put($emlPath, $email['raw'] ?? '');
        } catch (\Throwable) {
            $emlPath = null;
        }

        $followups = $report->metadata['followups'] ?? [];
        $followups[] = array_filter([
            'received_at' => now()->toIso8601String(),
            'from' => $fromEmail,
            'from_name' => $fromName,
            'subject' => $subject,
            'body' => mb_substr($body, 0, 4000),
            'message_id' => $email['message_id'] ?? null,
            'attachment' => $emlPath,
            'received_via_connection_id' => $connection->id,
        ]);

        $report->update([
            'metadata' => array_merge($report->metadata ?? [], ['followups' => $followups]),
        ]);

        \App\Models\InboxNotification::create([
            'type' => \App\Models\InboxNotification::TYPE_REPORT_FOLLOWUP,
            'report_id' => $report->id,
            'title' => 'Reporter follow-up on report' . ($report->target_ip ? " for {$report->target_ip}" : ''),
            'excerpt' => trim(($subject ? "{$subject} — " : '') . mb_substr($body, 0, 200)),
            'meta' => [
                'from' => $fromEmail,
                'from_name' => $fromName,
                'message_id' => $email['message_id'] ?? null,
            ],
        ]);

        Log::info('Follow-up added to case-less report', [
            'report_id' => $report->id,
            'from' => $fromEmail,
            'subject' => $subject,
        ]);
    }

    /**
     * Use AI classification if confident, otherwise fall back to keyword guessing.
     */
    protected function resolveAbuseType(?array $aiClassification, string $subject, string $body): string
    {
        if ($aiClassification) {
            $confidence = $aiClassification['confidence'] ?? 0;
            $type = $aiClassification['type'] ?? null;
            $validTypes = ['spam', 'phishing', 'malware', 'ddos', 'csam', 'copyright', 'fraud',
                'law_enforcement', 'brute_force', 'intrusion', 'botnet', 'other'];

            if ($confidence >= 0.7 && $type && in_array($type, $validTypes)) {
                return $type;
            }
        }

        return $this->guessAbuseType($subject, $body);
    }

    protected function guessAbuseType(string $subject, string $body): string
    {
        $text = strtolower($subject . ' ' . substr($body, 0, 3000));

        if (str_contains($text, 'law enforcement') || str_contains($text, 'police') || str_contains($text, 'criminal')
            || str_contains($text, 'court order') || str_contains($text, 'subpoena') || str_contains($text, 'cybercrime')
            || str_contains($text, 'subscriber information') || str_contains($text, 'provide information about')) return 'law_enforcement';
        if (str_contains($text, 'csam') || str_contains($text, 'child')) return 'csam';
        if (str_contains($text, 'phish')) return 'phishing';
        if (str_contains($text, 'malware') || str_contains($text, 'virus') || str_contains($text, 'ransomware')) return 'malware';
        if (str_contains($text, 'botnet') || str_contains($text, 'c2') || str_contains($text, 'c&c')) return 'botnet';
        if (str_contains($text, 'ddos') || str_contains($text, 'denial of service') || str_contains($text, 'amplification')) return 'ddos';
        if (str_contains($text, 'brute force') || str_contains($text, 'port scan')) return 'brute_force';
        if (str_contains($text, 'intrusion') || str_contains($text, 'hacking') || str_contains($text, 'exploit')) return 'intrusion';
        if (str_contains($text, 'spam') || str_contains($text, 'uce') || str_contains($text, 'unsolicited') || str_contains($text, 'bulk mail')) return 'spam';
        if (str_contains($text, 'copyright') || str_contains($text, 'dmca') || str_contains($text, 'infringement')) return 'copyright';
        if (str_contains($text, 'fraud') || str_contains($text, 'scam')) return 'fraud';

        return 'other';
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

    protected function sanitizeUtf8(string $text): string
    {
        $text = str_replace("\0", '', $text);

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $detected = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($detected && $detected !== 'UTF-8') {
            $converted = mb_convert_encoding($text, 'UTF-8', $detected);
            if ($converted !== false) {
                return $converted;
            }
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
