<?php

namespace App\Http\Requests;

use App\Enums\AbuseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class SubmitAbuseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'abuse_type' => ['required', Rule::enum(AbuseType::class)],
            'reporter_name' => ['required', 'string', 'max:255'],
            'reporter_email' => ['required', 'email', 'max:255'],
            'target_ip' => ['nullable', 'ip'],
            'target_domain' => ['nullable', 'string', 'max:255'],
            'target_url' => ['nullable', 'url', 'max:2048'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'evidence_files' => ['nullable', 'array', 'max:5'],
            // Allow-list for evidence files. mimetypes: matches the actual
            // sniffed MIME (server-side), not the user-supplied extension —
            // so a renamed .php is rejected even if the form widget accepts
            // it. Reporters who need to attach something else can paste it
            // into the description.
            'evidence_files.*' => [
                'file',
                'max:10240', // 10 MB each
                'mimetypes:'
                    .'image/png,image/jpeg,image/gif,image/webp,'
                    .'application/pdf,'
                    .'text/plain,text/csv,'
                    .'message/rfc822,application/x-mimearchive,'
                    .'application/octet-stream', // .eml/.log often arrive as octet-stream
            ],
        ];

        // Require reCAPTCHA when configured
        if (config('services.recaptcha.secret_key')) {
            $rules['g-recaptcha-response'] = ['required', 'string'];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->verifyRecaptcha()) {
                $validator->errors()->add('g-recaptcha-response', 'reCAPTCHA verification failed. Please try again.');
            }
        });
    }

    protected function verifyRecaptcha(): bool
    {
        $secret = config('services.recaptcha.secret_key');
        if (! $secret) {
            return true; // Skip if not configured
        }

        $token = $this->input('g-recaptcha-response');
        if (! $token) {
            return false;
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $this->ip(),
            ]);

            $result = $response->json();

            return ($result['success'] ?? false) && ($result['score'] ?? 0) >= 0.5;
        } catch (\Throwable) {
            return true; // Allow on network errors to avoid blocking legitimate reports
        }
    }
}
