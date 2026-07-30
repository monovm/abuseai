<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name', 'Abuse AI'))</title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="AbuseAI.io" />
    <link rel="manifest" href="/site.webmanifest" />
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; color: #1f2937; }
        .nav { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 1.5rem; height: 56px; display: flex; align-items: center; justify-content: space-between; }
        .nav-brand { font-weight: 700; font-size: 1rem; }
        .container { max-width: 960px; margin: 0 auto; padding: 2rem 1rem; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
        .badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 999px; font-size: 0.6875rem; font-weight: 500; }
        .badge-open { background: #dbeafe; color: #1e40af; }
        .badge-investigating { background: #e0e7ff; color: #3730a3; }
        .badge-actioned { background: #fef3c7; color: #92400e; }
        .badge-resolved { background: #d1fae5; color: #065f46; }
        .badge-closed { background: #f3f4f6; color: #6b7280; }
        .btn { display: inline-flex; align-items: center; padding: 0.4375rem 0.875rem; border-radius: 6px; font-size: 0.8125rem; font-weight: 500; border: none; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .text-muted { color: #6b7280; }
        .text-sm { font-size: 0.8125rem; }
        .mt-4 { margin-top: 1rem; }
        label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.375rem; }
        textarea, input { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem; }
        textarea { min-height: 120px; resize: vertical; }
        .alert-success { background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .badge-low { background: #dbeafe; color: #1e40af; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-high { background: #fed7aa; color: #9a3412; }
        .badge-critical { background: #fecaca; color: #991b1b; }
        @media (max-width: 640px) {
            .container { padding: 1rem 0.75rem; }
            .card { padding: 1rem; }
            .resp-grid-2 { grid-template-columns: 1fr !important; }
            .btn { min-height: 44px; padding: 0.5rem 1rem; }
        }
    </style>
</head>
<body>
    <nav class="nav">
        <div class="nav-brand" style="display:flex; align-items:center; gap:8px;"><img src="/abuseai-logo.webp" alt="{{ config('app.name', 'Abuse AI') }}" style="height:28px; width:auto;"></div>
        <div class="text-sm text-muted">{{ auth()->user()?->email ?? '' }}</div>
    </nav>
    <div class="container">
        @yield('content')
    </div>
</body>
</html>
