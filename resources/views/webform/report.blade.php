@php
    $cfg = is_array($brand?->report_config ?? null) ? $brand->report_config : [];
    $brandName = $brand?->name ?? config('app.name', 'Abuse AI');
    $pageTitle = $cfg['page_title'] ?? "Report Abuse — {$brandName}";
    $intro = $cfg['intro'] ?? 'Use this form to report abusive activity originating from our network.';
@endphp

<x-webform.brand-shell
    :title="$pageTitle"
    heading="Report Abuse"
    :intro="$intro"
>
    @if(session('success'))
        <div class="alert-success">{{ session('success') }}</div>
    @endif

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
        <form method="POST" action="{{ route('report.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="field">
                <label for="abuse_type">Abuse Type *</label>
                <select name="abuse_type" id="abuse_type" required>
                    <option value="">Select type...</option>
                    @foreach(\App\Enums\AbuseType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(old('abuse_type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="row">
                <div class="field">
                    <label for="reporter_name">Your Name *</label>
                    <input type="text" name="reporter_name" id="reporter_name" value="{{ old('reporter_name') }}" required>
                </div>
                <div class="field">
                    <label for="reporter_email">Your Email *</label>
                    <input type="email" name="reporter_email" id="reporter_email" value="{{ old('reporter_email') }}" required>
                </div>
            </div>

            <div class="row">
                <div class="field">
                    <label for="target_ip">Target IP</label>
                    <input type="text" name="target_ip" id="target_ip" value="{{ old('target_ip') }}" placeholder="e.g. 192.168.1.1">
                </div>
                <div class="field">
                    <label for="target_domain">Target Domain</label>
                    <input type="text" name="target_domain" id="target_domain" value="{{ old('target_domain') }}" placeholder="e.g. example.com">
                </div>
            </div>

            <div class="field">
                <label for="target_url">Target URL</label>
                <input type="url" name="target_url" id="target_url" value="{{ old('target_url') }}" placeholder="https://...">
            </div>

            <div class="field">
                <label for="description">Description *</label>
                <textarea name="description" id="description" required placeholder="Describe the abusive activity, including any relevant details...">{{ old('description') }}</textarea>
            </div>

            <div class="field">
                <label for="evidence_files">Evidence Files</label>
                <input type="file" name="evidence_files[]" id="evidence_files" multiple accept=".txt,.eml,.png,.jpg,.jpeg,.pdf,.log,.csv">
                <div class="hint">Max 5 files, 10MB each. Accepted: txt, eml, png, jpg, pdf, log, csv</div>
            </div>

            {{-- reCAPTCHA v3 --}}
            @if(config('services.recaptcha.site_key'))
                <input type="hidden" name="g-recaptcha-response" id="recaptcha-response">
            @endif

            <button type="submit" class="btn">Submit Report</button>
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
                        grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'abuse_report'}).then(function(token) {
                            document.getElementById('recaptcha-response').value = token;
                            form.submit();
                        });
                    });
                });
            </script>
        </x-slot>
    @endif
</x-webform.brand-shell>
