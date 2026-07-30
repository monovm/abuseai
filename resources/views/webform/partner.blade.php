@php
    $brandName = $brand?->name ?? config('app.name', 'Abuse AI');
    $supportEmail = $brand?->report_config['support_email'] ?? $brand?->reply_to_email ?? $brand?->from_email;
    $apiBase = url('/api/v1');
    $partnerToken = session('partner_token');
    $partnerEmail = session('partner_email');
@endphp

<x-webform.brand-shell
    :title="'API Partner Registration — ' . $brandName"
    heading="Become an API Partner"
    intro="Register to receive an API token and submit abuse reports programmatically."
>
    @if($partnerToken)
        <div class="alert-success">
            <strong>Account created.</strong> Save the token below — it is shown <strong>once</strong> and cannot be recovered.
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Your API Token</h2>
            <p class="hint" style="margin-top:0;">Registered to <strong>{{ $partnerEmail }}</strong>.</p>

            <div class="token-display" id="apiToken">{{ $partnerToken }}</div>
            <div style="margin-top:0.75rem; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <button type="button" class="copy-btn" onclick="copyToken()">Copy token</button>
                <span id="copyStatus" class="hint" style="margin:0;"></span>
            </div>

            <h3>Quick start</h3>
            <p style="margin-top:0.25rem;">Send the token in the <code class="inline">X-API-Key</code> header:</p>
<pre class="code-block">curl -X POST {{ $apiBase }}/reports \
  -H "X-API-Key: {{ $partnerToken }}" \
  -H "Content-Type: application/json" \
  -d '{
    "abuse_type": "phishing",
    "target_ip": "203.0.113.42",
    "target_url": "https://phishing.example/login",
    "description": "Credential-harvesting page mimicking ACME bank"
  }'</pre>

            <div style="margin-top:1.25rem;">
                <a href="{{ route('api.docs') }}" class="btn">View full API reference</a>
                <a href="{{ route('partner.show') }}" class="btn btn-secondary" style="margin-left:0.5rem;">Done</a>
            </div>
        </div>

        <x-slot name="scripts">
            <script>
                function copyToken() {
                    var token = document.getElementById('apiToken').textContent.trim();
                    navigator.clipboard.writeText(token).then(function () {
                        var s = document.getElementById('copyStatus');
                        s.textContent = 'Copied.';
                        setTimeout(function () { s.textContent = ''; }, 2000);
                    });
                }
            </script>
        </x-slot>
    @else
        @if($errors->any())
            <div class="alert-error">
                <ul style="margin:0; padding-left:1.25rem;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card">
            <h2 style="margin-top:0;">Why register?</h2>
            <ul class="checklist">
                <li>Submit reports via REST without filling out the web form.</li>
                <li>Higher rate limits than anonymous reporters.</li>
                <li>Per-reporter reputation: trusted partners ship faster through triage.</li>
                <li>Bulk endpoint for high-volume feeds (up to 100 reports per call).</li>
            </ul>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Register</h2>
            <form method="POST" action="{{ route('partner.register') }}">
                @csrf

                <div class="row">
                    <div class="field">
                        <label for="name">Your Name *</label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="255">
                    </div>
                    <div class="field">
                        <label for="email">Your Email *</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="255">
                        <div class="hint">We'll use this to contact you about your token and reports.</div>
                    </div>
                </div>

                <div class="row">
                    <div class="field">
                        <label for="organization">Organization</label>
                        <input type="text" id="organization" name="organization" value="{{ old('organization') }}" maxlength="255" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label for="website">Website</label>
                        <input type="url" id="website" name="website" value="{{ old('website') }}" maxlength="255" placeholder="https://...">
                    </div>
                </div>

                <div class="field">
                    <label for="intended_use">How will you use the API? *</label>
                    <textarea id="intended_use" name="intended_use" required minlength="20" maxlength="2000" placeholder="Briefly describe the data sources you'll forward, expected volume, and your abuse-handling workflow.">{{ old('intended_use') }}</textarea>
                    <div class="hint">Helps us route your reports correctly and grant trusted-reporter status when warranted.</div>
                </div>

                <div class="field">
                    <label style="display:flex; align-items:flex-start; gap:8px; font-weight:400;">
                        <input type="checkbox" name="agree" value="1" required style="width:auto; margin-top:3px;" {{ old('agree') ? 'checked' : '' }}>
                        <span>I agree to submit only good-faith abuse reports and accept that abusive submissions may result in token revocation.</span>
                    </label>
                </div>

                @if(config('services.recaptcha.site_key'))
                    <input type="hidden" name="g-recaptcha-response" id="recaptcha-response">
                @endif

                <button type="submit" class="btn">Register & Get Token</button>
                <a href="{{ route('api.docs') }}" class="btn btn-secondary" style="margin-left:0.5rem;">Read the docs first</a>
            </form>
        </div>

        @if(config('services.recaptcha.site_key'))
            <x-slot name="scripts">
                <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
                <script>
                    document.querySelector('form').addEventListener('submit', function(e) {
                        e.preventDefault();
                        var form = this;
                        grecaptcha.ready(function() {
                            grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'partner_register'}).then(function(token) {
                                document.getElementById('recaptcha-response').value = token;
                                form.submit();
                            });
                        });
                    });
                </script>
            </x-slot>
        @endif
    @endif
</x-webform.brand-shell>
