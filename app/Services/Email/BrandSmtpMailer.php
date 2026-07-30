<?php

namespace App\Services\Email;

use App\Models\Brand;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends email through a brand's own mailbox over SMTP. Used as the fallback
 * transport when Mandrill rejects a message or is unreachable, so outbound
 * mail keeps flowing per-brand even while the ESP is down.
 */
class BrandSmtpMailer
{
    public function isAvailableFor(?Brand $brand): bool
    {
        return $brand?->smtpFallbackConfig() !== null;
    }

    /**
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function sendFor(
        ?Brand $brand,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromEmail,
        string $fromName,
        ?string $replyTo = null,
        ?string $inReplyTo = null,
    ): array {
        $smtp = $brand?->smtpFallbackConfig();
        if (! $smtp) {
            return ['success' => false, 'message_id' => null, 'error' => 'No SMTP fallback configured for brand'];
        }

        try {
            $mailer = Mail::build([
                'transport' => 'smtp',
                'host' => $smtp['host'],
                'port' => $smtp['port'],
                'username' => $smtp['username'],
                'password' => $smtp['password'],
                // 'smtps' is implicit TLS from byte one (the 465 convention);
                // plain 'smtp' upgrades via STARTTLS when the server offers it.
                'scheme' => $smtp['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            ]);

            $messageId = null;

            $sent = $mailer->html($htmlBody, function (Message $message) use (
                $toEmail, $toName, $subject, $textBody, $fromEmail, $fromName, $replyTo, $inReplyTo, &$messageId,
            ) {
                $message->to($toEmail, $toName)
                    ->from($fromEmail, $fromName)
                    ->subject($subject)
                    ->text($textBody);

                // Set the Message-ID ourselves: SentMessage::getMessageId()
                // gets overwritten with the MTA queue id ("250 Ok queued as X"),
                // but reply threading matches inbound In-Reply-To headers
                // against sent_emails.message_id, so we must store the RFC id
                // in raw header form (angle brackets included).
                $email = $message->getSymfonyMessage();
                $id = $email->generateMessageId();
                $email->getHeaders()->addIdHeader('Message-ID', $id);
                $messageId = '<' . $id . '>';

                if ($replyTo) {
                    $message->replyTo($replyTo);
                }

                if ($inReplyTo) {
                    // Threading headers are best-effort: a malformed inbound
                    // Message-ID must never abort the send itself.
                    try {
                        $headers = $message->getSymfonyMessage()->getHeaders();
                        $id = trim($inReplyTo, '<> ');
                        $headers->addIdHeader('In-Reply-To', $id);
                        $headers->addIdHeader('References', $id);
                    } catch (\Throwable $e) {
                        Log::info('Skipping threading headers on SMTP fallback send', [
                            'in_reply_to' => $inReplyTo,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

            return [
                'success' => $sent !== null,
                'message_id' => $sent !== null ? $messageId : null,
                'error' => $sent !== null ? null : 'Send was suppressed by a listener',
            ];
        } catch (\Throwable $e) {
            Log::error('Brand SMTP send failed', [
                'brand' => $brand->name,
                'host' => $smtp['host'],
                'to' => $toEmail,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }
}
