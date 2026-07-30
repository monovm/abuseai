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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Design tokens — referenced as CSS variables across admin views */
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --danger: #dc2626;
            --success: #10b981;
            --warning: #f59e0b;
            --border: #e5e7eb;
            --text-muted: #6b7280;
        }
        .text-danger { color: var(--danger); }
        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-primary { color: var(--primary); }

        /* Badge variants */
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500; }
        /* "Mark as ..." outlined-pill action — used for inactive toggle cells
           (LE / Trusted / Blocked). Reads as a click-to-add affordance, not a
           status. Hover bumps it visually so users notice it's interactive. */
        .badge-mark {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 500;
            background: transparent;
            color: #6b7280;
            border: 1px dashed #d1d5db;
            cursor: pointer;
            transition: all 0.12s ease;
        }
        .badge-mark:hover { background: #f3f4f6; color: #1f2937; border-color: #9ca3af; }
        .badge-mark:focus { outline: 2px solid #2563eb; outline-offset: 1px; }
        .badge-low { background: #dbeafe; color: #1e40af; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-high { background: #fed7aa; color: #9a3412; }
        .badge-critical { background: #fecaca; color: #991b1b; }
        .badge-open { background: #dbeafe; color: #1e40af; }
        .badge-investigating { background: #e0e7ff; color: #3730a3; }
        .badge-actioned { background: #fef3c7; color: #92400e; }
        .badge-resolved { background: #d1fae5; color: #065f46; }
        .badge-closed { background: #f3f4f6; color: #6b7280; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-failed { background: #fecaca; color: #991b1b; }
        .badge-success { background: #d1fae5; color: #065f46; }

        /* Alpine: hide x-cloak elements until Alpine initialises */
        [x-cloak] { display: none !important; }

        /* Table */
        .table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table th { text-align: left; padding: 10px 12px; border-bottom: 2px solid #e5e7eb; font-weight: 500; color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
        .table td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; }
        .table tr:hover td { background: #f9fafb; }

        /* KPI */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; }
        .kpi-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; }
        .kpi-value { font-size: 24px; font-weight: 700; margin-top: 4px; }

        /* Filters */
        .filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; }
        .filters select, .filters input { padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; max-width: 100%; }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 500; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: all 0.15s; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-warning { background: #f59e0b; color: #fff; }
        .btn-warning:hover { background: #d97706; }
        .btn-purple { background: #7c3aed; color: #fff; }
        .btn-purple:hover { background: #6d28d9; }
        .btn-secondary { background: #fff; color: #1f2937; border-color: #e5e7eb; }
        .btn-secondary:hover { background: #f9fafb; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }

        /* Card */
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; overflow-x: auto; }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        .card-title { font-size: 15px; font-weight: 600; }

        /* Timeline */
        .timeline { position: relative; padding-left: 24px; }
        .timeline::before { content: ''; position: absolute; left: 6px; top: 0; bottom: 0; width: 2px; background: #e5e7eb; }
        .timeline-item { position: relative; padding-bottom: 20px; }
        .timeline-item::before { content: ''; position: absolute; left: -19px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: var(--primary); border: 2px solid #fff; }
        .timeline-time { font-size: 11px; color: #9ca3af; }
        .timeline-content { font-size: 13px; margin-top: 2px; }

        /* AI Panel */
        .ai-panel { background: #f5f3ff; border: 1px solid #c4b5fd; border-radius: 8px; padding: 16px; }
        .ai-panel-title { font-size: 14px; font-weight: 600; color: #5b21b6; margin-bottom: 12px; }
        .ai-draft { background: #fff; border: 1px solid #ddd6fe; border-radius: 6px; padding: 12px; margin-bottom: 12px; font-size: 13px; white-space: pre-wrap; }

        /* Utility */
        .flex { display: flex; }
        .gap-2 { gap: 8px; }
        .gap-4 { gap: 16px; }
        .mt-4 { margin-top: 16px; }
        .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; }
        .text-muted { color: #6b7280; }
        .text-sm { font-size: 13px; }

        /* Threat Intel */
        .intel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .intel-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; }
        .intel-card-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin-bottom: 8px; }
        .intel-stat { font-size: 20px; font-weight: 700; }

        /* Layout */
        .admin-sidebar { position: fixed; top: 0; left: 0; width: 240px; height: 100vh; background: #1e293b; color: #fff; overflow-y: auto; z-index: 50; transition: transform 0.25s ease; }
        .admin-main { margin-left: 240px; min-height: 100vh; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 45; }
        .hamburger { display: none; background: none; border: none; padding: 8px; cursor: pointer; color: #1f2937; }
        .hamburger svg { width: 24px; height: 24px; }
        .sidebar-nav-link { display: flex; align-items: center; padding: 10px 20px; font-size: 14px; text-decoration: none; transition: background 0.15s; }
        .sidebar-nav-link.active { background: rgba(255,255,255,0.1); color: #fff; }
        .sidebar-nav-link:not(.active) { color: #94a3b8; }
        .sidebar-nav-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .sidebar-section { padding: 12px 20px 6px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; }
        .sidebar-sub-link { display: flex; align-items: center; padding: 5px 20px 5px 40px; font-size: 12px; text-decoration: none; transition: background 0.15s; color: #64748b; }
        .sidebar-sub-link:not(.active):hover { background: rgba(255,255,255,0.05); color: #cbd5e1; }
        .sidebar-sub-link.active { background: rgba(255,255,255,0.08); color: #fff; }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .admin-sidebar { transform: translateX(-100%); }
            .admin-sidebar.open { transform: translateX(0); }
            .sidebar-overlay.open { display: block; }
            .admin-main { margin-left: 0; }
            .hamburger { display: block; }
            .grid-2 { grid-template-columns: 1fr; }
            .intel-grid { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
            .card { padding: 12px; }
            .table { font-size: 12px; }
            .table th, .table td { padding: 8px 6px; }
            .filters { gap: 6px; }
            .filters select, .filters input { font-size: 12px; padding: 5px 8px; max-width: 100%; }
            .filters input[type="text"] { width: 100% !important; min-width: 0; }
            .resp-grid-2 { grid-template-columns: 1fr !important; }
            .resp-grid-3 { grid-template-columns: 1fr !important; }
            .resp-grid-4 { grid-template-columns: 1fr 1fr !important; }
            .admin-content { padding: 10px !important; }
            .btn { padding: 8px 12px; min-height: 44px; display: inline-flex; align-items: center; }
            .btn-sm { min-height: 36px; }
        }

        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .kpi-value { font-size: 20px; }
            .resp-grid-4 { grid-template-columns: 1fr !important; }
            .filters { flex-direction: column; }
            .filters select, .filters input { width: 100% !important; }
            .card-header { flex-direction: column; align-items: flex-start; }
        }

        @media (max-width: 768px) {
            .sidebar-close { display: block !important; }
        }

        /* Table horizontal scroll on mobile */
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table code { word-break: break-all; }
        .text-break { word-break: break-all; overflow-wrap: break-word; }
    </style>
    @livewireStyles
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; color: #1f2937;">
    {{-- Sidebar overlay (mobile) --}}
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    {{-- Sidebar --}}
    <aside class="admin-sidebar" id="sidebar">
        <div style="padding:12px 20px; border-bottom:1px solid rgba(255,255,255,0.1); display:flex; align-items:center; justify-content:space-between;">
            <a href="/admin/cases" style="text-decoration:none; display:flex; align-items:center; gap:10px;">
                <img src="/abuseai-logo.webp" alt="{{ config('app.name') }}" style="height:30px; width:auto;">
            </a>
            <button onclick="toggleSidebar()" style="display:none; background:none; border:none; color:#94a3b8; cursor:pointer; padding:4px;" class="sidebar-close">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l8 8M14 6l-8 8"/></svg>
            </button>
        </div>

        <div class="sidebar-section">Overview</div>
        <nav>
            <a href="/admin/dashboard" class="sidebar-nav-link {{ request()->is('admin/dashboard*') ? 'active' : '' }}">Dashboard</a>
        </nav>

        @php
            // Pre-filtered sub-nav. Cases skip the URL filter — abuse_cases
            // has no target_url column; reports include it.
            $caseSubFilters = [
                ['target', 'ip', 'IP targets'],
                ['target', 'domain', 'Domain targets'],
                ['type', 'law_enforcement', 'Law enforcement'],
            ];
            $reportSubFilters = [
                ['target', 'ip', 'IP targets'],
                ['target', 'domain', 'Domain targets'],
                ['target', 'url', 'URL targets'],
                ['type', 'law_enforcement', 'Law enforcement'],
            ];
            // Active state needs both the path and the query value to match,
            // otherwise the parent link would also light up when a child does.
            $caseFiltersActive = collect($caseSubFilters)->contains(fn ($f) => request()->query($f[0]) === $f[1]) && request()->is('admin/cases*');
            $reportFiltersActive = collect($reportSubFilters)->contains(fn ($f) => request()->query($f[0]) === $f[1]) && request()->is('admin/reports*');
        @endphp

        <div class="sidebar-section">Cases</div>
        <nav>
            <a href="/admin/cases" class="sidebar-nav-link {{ request()->is('admin/cases*') && ! $caseFiltersActive ? 'active' : '' }}">Case Queue</a>
            @foreach($caseSubFilters as [$key, $value, $label])
                <a href="/admin/cases?{{ $key }}={{ $value }}" class="sidebar-sub-link {{ request()->is('admin/cases*') && request()->query($key) === $value ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
            <a href="/admin/reports" class="sidebar-nav-link {{ request()->is('admin/reports*') && ! $reportFiltersActive ? 'active' : '' }}">Reports</a>
            @foreach($reportSubFilters as [$key, $value, $label])
                <a href="/admin/reports?{{ $key }}={{ $value }}" class="sidebar-sub-link {{ request()->is('admin/reports*') && request()->query($key) === $value ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
            <a href="/admin/customers" class="sidebar-nav-link {{ request()->is('admin/customers*') ? 'active' : '' }}">Customers</a>
            <a href="/admin/ips" class="sidebar-nav-link {{ request()->is('admin/ips*') ? 'active' : '' }}">IP Inventory</a>
            <a href="/admin/reputation" class="sidebar-nav-link {{ request()->is('admin/reputation*') ? 'active' : '' }}">IP Reputation</a>
            <a href="/admin/abuseipdb" class="sidebar-nav-link {{ request()->is('admin/abuseipdb*') ? 'active' : '' }}">AbuseIPDB</a>
            <a href="/admin/email-inbox" class="sidebar-nav-link {{ request()->is('admin/email-inbox*') ? 'active' : '' }}">Email Inbox</a>
            <a href="/admin/reporters" class="sidebar-nav-link {{ request()->is('admin/reporters*') ? 'active' : '' }}">Reporters</a>
        </nav>

        <div class="sidebar-section">Insights</div>
        <nav>
            <a href="/admin/analytics" class="sidebar-nav-link {{ request()->is('admin/analytics*') ? 'active' : '' }}">Analytics</a>
            <a href="/admin/audit-log" class="sidebar-nav-link {{ request()->is('admin/audit-log*') ? 'active' : '' }}">Audit Log</a>
        </nav>

        @php
            // Settings + System sections are admin-only. Non-admins (agent,
            // viewer) get a leaner sidebar that just covers the work surface.
            $isAdmin = auth()->user()?->hasRole('admin') ?? false;
        @endphp

        @if($isAdmin)
            <div class="sidebar-section">Settings</div>
            <nav>
                <a href="/admin/users" class="sidebar-nav-link {{ request()->is('admin/users*') ? 'active' : '' }}">Users</a>
                <a href="/admin/brands" class="sidebar-nav-link {{ request()->is('admin/brands*') ? 'active' : '' }}">Brands & Email</a>
                <a href="/admin/rules" class="sidebar-nav-link {{ request()->is('admin/rules*') ? 'active' : '' }}">Automation Rules</a>
                <a href="/admin/templates" class="sidebar-nav-link {{ request()->is('admin/templates*') ? 'active' : '' }}">Email Templates</a>
                <a href="/admin/ai-settings" class="sidebar-nav-link {{ request()->is('admin/ai-settings*') ? 'active' : '' }}">AI Provider</a>
                <a href="/admin/ai-usage" class="sidebar-nav-link {{ request()->is('admin/ai-usage*') ? 'active' : '' }}">AI Usage</a>
            </nav>
        @endif

        <div class="sidebar-section">Help</div>
        <nav>
            <a href="/admin/guide" class="sidebar-nav-link {{ request()->is('admin/guide*') ? 'active' : '' }}">Admin Guide</a>
        </nav>

        @if($isAdmin)
            <div class="sidebar-section">System</div>
            <nav style="margin-bottom:16px;">
                <a href="/horizon" target="_blank" class="sidebar-nav-link">Horizon</a>
            </nav>
        @endif
    </aside>

    {{-- Main --}}
    <div class="admin-main">
        <header style="height:56px; background:#fff; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; padding:0 16px; position:sticky; top:0; z-index:40;">
            <div style="display:flex; align-items:center; gap:8px;">
                <button class="hamburger" onclick="toggleSidebar()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 style="font-size:16px; font-weight:600; margin:0;">@yield('header')</h1>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                @livewire('admin.notification-bell')
                <a href="/profile" style="font-size:13px; color:#6b7280; text-decoration:none;">{{ auth()->user()?->name ?? 'Admin' }}</a>
                <form method="POST" action="{{ route('logout') }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Logout</button>
                </form>
            </div>
        </header>
        <div style="padding:16px;" class="admin-content">
            {{-- Flash messages --}}
            @if(session('success'))
                <div style="padding:10px 16px; background:#d1fae5; border:1px solid #6ee7b7; border-radius:6px; margin-bottom:12px; font-size:13px; color:#065f46;">
                    {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div style="padding:10px 16px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; margin-bottom:12px; font-size:13px; color:#991b1b;">
                    {{ session('error') }}
                </div>
            @endif
            @if(session('warning'))
                <div style="padding:10px 16px; background:#fefce8; border:1px solid #fde68a; border-radius:6px; margin-bottom:12px; font-size:13px; color:#92400e;">
                    {{ session('warning') }}
                </div>
            @endif
            @yield('content')
        </div>
    </div>

    @livewireScripts
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
        }
        // Close sidebar on nav click (mobile)
        document.querySelectorAll('.sidebar-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar').classList.remove('open');
                    document.getElementById('sidebarOverlay').classList.remove('open');
                }
            });
        });
    </script>
</body>
</html>
