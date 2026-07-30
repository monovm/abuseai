<?php

namespace App\Http\Controllers\WebForm;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Reporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Self-service partner registration for the public abuse-report API.
 *
 * Flow: visitor fills the form, we mint a fresh token, store its bcrypt
 * hash on a Reporter row, and surface the plaintext token exactly once
 * via the session so we never persist it server-side. Existing
 * registrations have to go through brand support to rotate — keeps
 * drive-by token theft from rolling new keys silently.
 */
class PartnerController extends Controller
{
    public function show()
    {
        return view('webform.partner');
    }

    public function register(Request $request)
    {
        /** @var Brand|null $brand */
        $brand = $request->attributes->get('brand') ?? Brand::getDefault();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'intended_use' => ['required', 'string', 'min:20', 'max:2000'],
            'website' => ['nullable', 'url', 'max:255'],
            'agree' => ['accepted'],
        ];

        if (config('services.recaptcha.secret_key')) {
            $rules['g-recaptcha-response'] = ['required', 'string'];
        }

        $data = $request->validate($rules);

        if (config('services.recaptcha.secret_key') && ! $this->verifyRecaptcha($request)) {
            return back()
                ->withInput()
                ->withErrors(['g-recaptcha-response' => 'reCAPTCHA verification failed. Please try again.']);
        }

        $existing = Reporter::where('email', $data['email'])->first();
        if ($existing && $existing->api_key) {
            $supportEmail = $brand?->reply_to_email ?? $brand?->from_email;
            $supportNote = $supportEmail ? " Contact {$supportEmail} to rotate your token." : '';
            return back()
                ->withInput()
                ->withErrors([
                    'email' => 'This email already has a partner account.' . $supportNote,
                ]);
        }

        $token = 'abuse_' . Str::random(40);

        $metadata = array_filter([
            'organization' => $data['organization'] ?? null,
            'intended_use' => $data['intended_use'],
            'website' => $data['website'] ?? null,
            'registered_ip' => $request->ip(),
            'registered_at' => now()->toIso8601String(),
            'registered_brand' => $brand?->name,
            'registered_host' => $request->getHost(),
        ], fn ($v) => $v !== null && $v !== '');

        if ($existing) {
            $existing->update([
                'name' => $data['name'],
                'type' => 'api',
                'api_key' => Hash::make($token),
                'metadata' => array_merge($existing->metadata ?? [], $metadata),
            ]);
        } else {
            Reporter::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'type' => 'api',
                'api_key' => Hash::make($token),
                'reputation_score' => 50,
                'is_trusted' => false,
                'is_blocked' => false,
                'metadata' => $metadata,
            ]);
        }

        return redirect()
            ->route('partner.show')
            ->with('partner_token', $token)
            ->with('partner_email', $data['email']);
    }

    protected function verifyRecaptcha(Request $request): bool
    {
        $secret = config('services.recaptcha.secret_key');
        $token = $request->input('g-recaptcha-response');
        if (! $token) {
            return false;
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
            $result = $response->json();
            return ($result['success'] ?? false) && ($result['score'] ?? 0) >= 0.5;
        } catch (\Throwable) {
            // Network blip on the verifier shouldn't lock out legitimate sign-ups.
            return true;
        }
    }
}
