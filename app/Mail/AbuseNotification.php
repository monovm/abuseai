<?php

namespace App\Mail;

use App\Models\AbuseCase;
use App\Models\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AbuseNotification extends Mailable
{
    use Queueable, SerializesModels;

    public string $renderedBody;
    public string $renderedSubject;

    public function __construct(
        public EmailTemplate $template,
        public array $variables = [],
    ) {
        $this->renderedSubject = $this->replaceVars($template->subject, $variables);
        $this->renderedBody = $this->replaceVars($template->body_html, $variables);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->renderedBody);
    }

    public static function forReporterAck(AbuseCase $case, string $reporterName, string $reporterEmail): ?self
    {
        $template = EmailTemplate::active()->forType('reporter_ack', $case->abuse_type)->first();
        if (! $template) {
            return null;
        }

        return (new self($template, [
            'reporter_name' => $reporterName,
            'reporter_email' => $reporterEmail,
            'case_number' => $case->case_number,
            'abuse_type' => $case->abuse_type->value,
            'target_ip' => $case->target_ip ?? '',
        ]))->to($reporterEmail);
    }

    public static function forCustomerWarning(AbuseCase $case, string $customerEmail): ?self
    {
        $template = EmailTemplate::active()->forType('customer_warning', $case->abuse_type)->first();
        if (! $template) {
            return null;
        }

        return (new self($template, [
            'case_number' => $case->case_number,
            'abuse_type' => $case->abuse_type->value,
            'target_ip' => $case->target_ip ?? '',
            'target_domain' => $case->target_domain ?? '',
            'severity_level' => $case->severity_level->value,
        ]))->to($customerEmail);
    }

    public static function forCustomerSuspension(AbuseCase $case, string $customerEmail): ?self
    {
        $template = EmailTemplate::active()->forType('customer_suspension', $case->abuse_type)->first();
        if (! $template) {
            return null;
        }

        return (new self($template, [
            'case_number' => $case->case_number,
            'abuse_type' => $case->abuse_type->value,
            'target_ip' => $case->target_ip ?? '',
            'severity_level' => $case->severity_level->value,
        ]))->to($customerEmail);
    }

    protected function replaceVars(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace("{{" . $key . "}}", (string) $value, $text);
        }

        return $text;
    }
}
