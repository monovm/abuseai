{{--
    Shared chrome for the public, per-brand pages (/report, /partner, /api).
    Hosts that resolve to a brand pick up colour, logo, favicon and footer
    from their Brand row; unmatched hosts fall back to the default brand
    so we never serve unbranded HTML.
--}}
@props([
    'title' => 'Abuse Desk',
    'heading' => 'Abuse Desk',
    'intro' => null,
    'maxWidth' => '640px',
])
@php
    $cfg = is_array($brand?->report_config ?? null) ? $brand->report_config : [];
    $primary = $cfg['primary_color'] ?? '#2563eb';
    $brandName = $brand?->name ?? config('app.name', 'Abuse AI');
    $logoUrl = $brand?->logo_url ?: '/abuseai-logo.webp';
    $faviconUrl = $cfg['favicon_url'] ?? ($brand?->logo_url ?: null);
    // Only http(s) and data:image URLs are safe to drop into <link href>.
    // Anything else (javascript:, vbscript:, file:, …) becomes stored XSS
    // when an admin sets it, so fall back to no favicon rather than render.
    if ($faviconUrl && ! preg_match('#^(https?://|data:image/)#i', $faviconUrl)) {
        $faviconUrl = null;
    }
    $websiteUrl = $brand?->website_url;
    $supportEmail = $cfg['support_email'] ?? $brand?->reply_to_email ?? $brand?->from_email;
    $footerHtml = $cfg['footer_html'] ?? null;
    $footerLinks = is_array($cfg['footer_links'] ?? null) ? $cfg['footer_links'] : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    @if($faviconUrl)
        <link rel="icon" href="{{ $faviconUrl }}" />
        <link rel="shortcut icon" href="{{ $faviconUrl }}" />
        <link rel="apple-touch-icon" href="{{ $faviconUrl }}" />
    @else
        <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        <link rel="shortcut icon" href="/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    @endif
    <meta name="apple-mobile-web-app-title" content="{{ $brandName }}" />
    <link rel="manifest" href="/site.webmanifest" />
    <style>
        :root { --primary: {{ $primary }}; --primary-dark: {{ $primary }}; }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; margin: 0; padding: 2rem 1rem; color: #1f2937; }
        .container { max-width: {{ $maxWidth }}; margin: 0 auto; }
        .brand-header { text-align: center; margin-bottom: 2rem; }
        .brand-logo-link { display: inline-block; line-height: 0; }
        .brand-logo { display: block; max-height: 56px; max-width: 240px; width: auto; height: auto; margin: 0 auto 1rem; }
        h1 { font-size: 1.75rem; margin: 0 0 0.5rem; }
        h2 { font-size: 1.25rem; margin: 1.5rem 0 0.75rem; }
        h3 { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
        .subtitle { color: #6b7280; margin: 0; }
        .nav-links { margin-top: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: center; font-size: 0.875rem; }
        .nav-links a { color: var(--primary); text-decoration: none; }
        .nav-links a:hover { text-decoration: underline; }
        .nav-links .sep { color: #d1d5db; }
        .card { background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card + .card { margin-top: 1rem; }
        .field { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.375rem; }
        input, select, textarea { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(0,0,0,0.05); }
        textarea { min-height: 120px; resize: vertical; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .btn { background: var(--primary); color: #fff; border: none; padding: 0.625rem 1.5rem; border-radius: 6px; font-size: 0.875rem; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { filter: brightness(0.92); }
        .btn-secondary { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .alert-success { background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
        .alert-error { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
        .error-text { color: #dc2626; font-size: 0.75rem; margin-top: 0.25rem; }
        .hint { color: #9ca3af; font-size: 0.75rem; margin-top: 0.25rem; }
        .footer { margin-top: 2rem; color: #6b7280; font-size: 0.8125rem; text-align: center; }
        .footer a { color: var(--primary); text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        .footer-links { margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: center; }
        .footer-html { margin-top: 0.75rem; }

        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, monospace; }
        code.inline { background: #f3f4f6; padding: 1px 6px; border-radius: 4px; font-size: 0.8125rem; }
        pre.code-block { background: #0f172a; color: #e2e8f0; padding: 12px 14px; border-radius: 6px; font-size: 12px; line-height: 1.55; overflow-x: auto; margin: 0; }
        .endpoint { border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem 1.25rem; margin-top: 1rem; background: #fafafa; }
        .endpoint-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 0.5rem; }
        .method { font-family: ui-monospace, monospace; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; color: #fff; letter-spacing: 0.04em; }
        .method-get { background: #059669; }
        .method-post { background: #2563eb; }
        .method-put { background: #d97706; }
        .method-delete { background: #dc2626; }
        .endpoint-path { font-family: ui-monospace, monospace; font-size: 13px; color: #1f2937; word-break: break-all; }
        table.params { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 0.5rem; }
        table.params th, table.params td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.params th { color: #6b7280; font-weight: 600; font-size: 11px; text-transform: uppercase; }
        table.params td.name { font-family: ui-monospace, monospace; color: #111827; white-space: nowrap; }
        table.params td.req { color: #dc2626; font-weight: 600; }
        .token-display { font-family: ui-monospace, monospace; font-size: 13px; word-break: break-all; background: #fff; padding: 10px 12px; border: 1px solid #fcd34d; border-radius: 6px; }
        .copy-btn { background: #fff; border: 1px solid #d1d5db; padding: 4px 10px; border-radius: 4px; font-size: 11px; cursor: pointer; margin-left: 8px; }
        .copy-btn:hover { background: #f3f4f6; }
        ul.checklist { padding-left: 1.25rem; margin: 0.5rem 0; }
        ul.checklist li { margin-bottom: 0.25rem; }
    </style>
    {{ $head ?? '' }}
</head>
<body>
    <div class="container">
        <div class="brand-header">
            @if($websiteUrl)
                <a href="{{ $websiteUrl }}" target="_blank" rel="noopener" class="brand-logo-link">
                    <img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="brand-logo">
                </a>
            @else
                <img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="brand-logo">
            @endif
            <h1>{{ $heading }}</h1>
            @isset($intro)
                <p class="subtitle">{{ $intro }}</p>
            @endisset
            <div class="nav-links">
                <a href="{{ route('report.show') }}">Report Abuse</a>
                <span class="sep">·</span>
                <a href="{{ route('partner.show') }}">Get API Token</a>
                <span class="sep">·</span>
                <a href="{{ route('api.docs') }}">API Reference</a>
            </div>
        </div>

        {{ $slot }}

        <div class="footer">
            @if($supportEmail)
                <div>Questions? Email <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</div>
            @endif

            @if(! empty($footerLinks))
                <div class="footer-links">
                    @foreach($footerLinks as $link)
                        @if(! empty($link['url']) && ! empty($link['label']))
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener">{{ $link['label'] }}</a>
                        @endif
                    @endforeach
                </div>
            @endif

            @if($footerHtml)
                {{-- Footer HTML is brand-controlled and only editable by admins. --}}
                <div class="footer-html">{!! $footerHtml !!}</div>
            @endif

            <div style="margin-top:0.75rem;">&copy; {{ date('Y') }} {{ $brandName }}</div>
        </div>
    </div>

    {{ $scripts ?? '' }}
</body>
</html>
