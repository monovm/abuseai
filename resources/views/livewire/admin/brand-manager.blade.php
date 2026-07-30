<div>
    <x-flash-messages />

    {{-- Mandrill status + transport switch --}}
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <strong>Mandrill SMTP</strong>
                <span class="badge {{ $mandrillConfigured ? 'badge-resolved' : 'badge-critical' }}" style="margin-left:8px;">
                    {{ $mandrillConfigured ? 'Configured' : 'Not configured' }}
                </span>
                @if(! $mandrillEnabled)
                    <span class="badge badge-critical" style="margin-left:6px;">Disabled — using brand SMTP</span>
                @endif
                <span class="text-sm text-muted" style="margin-left:8px;">Set <code>MANDRILL_KEY</code> in .env — when Mandrill fails, brands fall back to their own mailbox (SMTP)</span>
            </div>
            <div style="display:flex; gap:8px;">
                @if($mandrillConfigured)
                    <button wire:click="testMandrill" class="btn btn-secondary btn-sm">Test Mandrill</button>
                @endif
                <button wire:click="toggleMandrill" class="btn btn-sm {{ $mandrillEnabled ? 'btn-secondary' : 'btn-primary' }}">
                    {{ $mandrillEnabled ? 'Disable Mandrill (use brand SMTP)' : 'Re-enable Mandrill' }}
                </button>
            </div>
        </div>
        @if(! $mandrillEnabled)
            <div class="text-sm text-muted" style="margin-top:8px; padding-top:8px; border-top:1px solid #e5e7eb;">
                Mandrill is switched <strong>off</strong>. Every send goes through each brand's own SMTP inbox
                (configured per brand below, or derived from the brand's inbox connection). Turn this back on once
                Mandrill is healthy again.
            </div>
        @endif
    </div>

    {{-- ===== BRANDS ===== --}}
    <div class="card-header" style="margin-bottom:0;">
        <div class="card-title">Brands</div>
        @if(! $showBrandForm)
            <button wire:click="createBrand" class="btn btn-primary btn-sm">+ New Brand</button>
        @endif
    </div>

    @if($showBrandForm)
        <div class="card" style="border:2px solid #2563eb;">
            <div class="card-title" style="margin-bottom:12px;">{{ $editingBrandId ? 'Edit Brand' : 'New Brand' }}</div>
            <form wire:submit="saveBrand">
                <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div><label for="brand-name" style="font-size:11px;">Name *</label><input id="brand-name" type="text" wire:model="brandName" placeholder="Acme" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label for="brand-slug" style="font-size:11px;">Slug *</label><input id="brand-slug" type="text" wire:model="brandSlug" placeholder="acme" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label for="brand-from-name" style="font-size:11px;">From Name *</label><input id="brand-from-name" type="text" wire:model="fromName" placeholder="Acme Abuse Desk" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label for="brand-from-email" style="font-size:11px;">From Email *</label><input id="brand-from-email" type="email" wire:model="fromEmail" placeholder="abuse@example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label for="brand-reply-to" style="font-size:11px;">Reply-To Email</label><input id="brand-reply-to" type="email" wire:model="replyToEmail" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:11px;">Website URL</label><input type="url" wire:model="websiteUrl" placeholder="https://example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:11px;">Logo URL</label><input type="url" wire:model="logoUrl" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div>
                        <label style="font-size:11px;">Mandrill Key (per-brand)</label>
                        <x-password-input wire:model="mandrillKey" placeholder="{{ $editingBrandId ? 'Leave blank to keep existing' : 'Enter Mandrill API key' }}" />
                    </div>
                </div>
                <div style="margin-top:12px;"><label style="font-size:11px;">Email Signature (HTML)</label><textarea wire:model="emailSignature" rows="3" placeholder="<p>Best regards,<br>Acme Abuse Team</p>" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:12px; font-family:monospace;"></textarea></div>
                <div style="margin-top:8px;"><label style="font-size:11px;">Email Footer (plain text)</label><input type="text" wire:model="emailFooter" placeholder="Acme Hosting | abuse@example.com | https://example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>

                {{-- SMTP fallback: the brand's own mailbox, used automatically when Mandrill fails --}}
                <div style="margin-top:16px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:4px;">SMTP Fallback (brand mailbox)</div>
                    <div style="font-size:11px; color:#6b7280; margin-bottom:8px;">
                        Used automatically when Mandrill fails or has no API key. Fields left blank are taken from this
                        brand's active inbox connection (same server &amp; login), so most brands need nothing here —
                        fill in only what differs for sending (e.g. host <code>smtp.…</code> instead of <code>mail.…</code>, or port 587).
                    </div>
                    <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div><label style="font-size:11px;">SMTP Host</label><input type="text" wire:model="smtpHost" placeholder="From inbox connection if blank" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                        <div><label style="font-size:11px;">SMTP Port</label><input type="number" min="1" max="65535" wire:model="smtpPort" placeholder="465 (SSL) / 587 (STARTTLS)" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                        <div><label style="font-size:11px;">SMTP Username</label><input type="text" wire:model="smtpUsername" placeholder="From inbox connection if blank" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                        <div>
                            <label style="font-size:11px;">SMTP Password</label>
                            <x-password-input wire:model="smtpPassword" placeholder="{{ $editingBrandId ? 'Blank = keep existing / use inbox password' : 'From inbox connection if blank' }}" />
                        </div>
                        <div>
                            <label style="font-size:11px;">Encryption</label>
                            <select wire:model="smtpEncryption" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                                <option value="">Auto (match inbox connection)</option>
                                <option value="ssl">SSL/TLS — implicit, port 465</option>
                                <option value="tls">STARTTLS — port 587</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                    </div>
                </div>
                {{-- Provider mode picker. Each option gates which credential
                     blocks below it actually save data — switching to "WHMCS only"
                     hides Virtualizor fields, etc. --}}
                <div style="margin-top:16px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">Infrastructure Provider</div>
                    <div style="display:flex; flex-direction:column; gap:6px; font-size:12px;">
                        @foreach(\App\Enums\ProviderType::cases() as $pt)
                            <label style="display:flex; align-items:flex-start; gap:8px; padding:6px 10px; border:1px solid {{ $providerType === $pt->value ? '#2563eb' : '#e5e7eb' }}; border-radius:6px; cursor:pointer; background:{{ $providerType === $pt->value ? '#eff6ff' : '#fff' }};">
                                <input type="radio" wire:model.live="providerType" value="{{ $pt->value }}" style="margin-top:2px;">
                                <div>
                                    <div style="font-weight:600;">{{ $pt->label() }}</div>
                                    <div style="color:#6b7280; font-size:11px;">{{ $pt->description() }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- WHMCS Config (shown for whmcs and whmcs_virtualizor modes) --}}
                @if(in_array($providerType, ['whmcs', 'whmcs_virtualizor'], true))
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">WHMCS Connection</div>
                    <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div><label style="font-size:11px;">WHMCS API URL</label><input type="url" wire:model="whmcsUrl" placeholder="https://my.example.com/includes/api.php" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                        <div><label style="font-size:11px;">WHMCS Admin URL</label><input type="url" wire:model="whmcsAdminUrl" placeholder="https://my.example.com/nexus/" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <div style="font-size:10px; color:#6b7280;">Admin panel base URL for direct links to clients/services</div>
                        </div>
                        <div>
                            <label style="font-size:11px;">API Identifier</label>
                            <x-password-input wire:model="whmcsIdentifier" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                        <div>
                            <label style="font-size:11px;">API Secret</label>
                            <x-password-input wire:model="whmcsSecret" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                        <div><label style="font-size:11px;">Abuse Ticket Department ID</label><input type="number" min="1" wire:model="whmcsTicketDepartmentId" placeholder="e.g. 3 — falls back to env default" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <div style="font-size:10px; color:#6b7280;">Support department ID used when the auto-suspend rule opens a client ticket. Find it in WHMCS under Setup &rarr; Support &rarr; Departments (the number in the URL).</div>
                        </div>
                        <div><label style="font-size:11px;">Admin Username (for OpenTicket)</label><input type="text" wire:model="whmcsAdminUsername" placeholder="e.g. 43 — falls back to env default" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <div style="font-size:10px; color:#6b7280;">WHMCS admin the abuse ticket is attributed to. Required by some WHMCS installs for the OpenTicket API to work; can be a numeric admin ID or username.</div>
                        </div>
                    </div>

                    {{-- Match strategy: only meaningful when Virtualizor is also
                         configured. For whmcs-only mode the lookup goes
                         straight to WHMCS by IP and this picker is hidden. --}}
                    @if($providerType === 'whmcs_virtualizor')
                    <div style="margin-top:12px;">
                        <div style="font-size:11px; font-weight:600; color:#374151; margin-bottom:6px;">Virtualizor → WHMCS match strategy</div>
                        <div style="display:flex; flex-direction:column; gap:4px; font-size:12px;">
                            @foreach(\App\Enums\WhmcsMatchStrategy::cases() as $ms)
                                <label style="display:flex; align-items:flex-start; gap:8px; padding:6px 10px; border:1px solid {{ $whmcsMatchStrategy === $ms->value ? '#2563eb' : '#e5e7eb' }}; border-radius:6px; cursor:pointer; background:{{ $whmcsMatchStrategy === $ms->value ? '#eff6ff' : '#fff' }};">
                                    <input type="radio" wire:model.live="whmcsMatchStrategy" value="{{ $ms->value }}" style="margin-top:2px;">
                                    <div>
                                        <div style="font-weight:600;">
                                            {{ $ms->label() }}
                                            @if($ms->requiresScan())
                                                <span style="color:#b45309; font-weight:500; font-size:10.5px; margin-left:4px;">(slow scan)</span>
                                            @endif
                                        </div>
                                        <div style="color:#6b7280; font-size:11px;">{{ $ms->description() }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>

                @endif

                {{-- Virtualizor Config (shown for virtualizor and whmcs_virtualizor modes) --}}
                @if(in_array($providerType, ['virtualizor', 'whmcs_virtualizor'], true))
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">Virtualizor Connection</div>
                    <div class="resp-grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                        <div><label style="font-size:11px;">Virtualizor URL</label><input type="url" wire:model="vzUrl" placeholder="https://panel.example.com:4085" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                        <div>
                            <label style="font-size:11px;">Admin API Key</label>
                            <x-password-input wire:model="vzApiKey" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                        <div>
                            <label style="font-size:11px;">Admin API Pass</label>
                            <x-password-input wire:model="vzApiPass" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                    </div>
                </div>

                @endif

                {{-- SolusVM v1 --}}
                @if($providerType === 'solusvm')
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">SolusVM v1 Connection</div>
                    <div class="resp-grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                        <div>
                            <label style="font-size:11px;">Admin API URL</label>
                            <input type="url" wire:model="solusVmUrl" placeholder="https://panel.example.com:5656/api/admin/command.php" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <div style="font-size:10px; color:#6b7280; margin-top:2px;">Full URL to <code>command.php</code>.</div>
                        </div>
                        <div>
                            <label style="font-size:11px;">API ID</label>
                            <x-password-input wire:model="solusVmApiId" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                        <div>
                            <label style="font-size:11px;">API Key</label>
                            <x-password-input wire:model="solusVmApiKey" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                    </div>
                </div>
                @endif

                {{-- Proxmox VE --}}
                @if($providerType === 'proxmox')
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">Proxmox VE Connection</div>
                    <div class="resp-grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                        <div>
                            <label style="font-size:11px;">Cluster URL</label>
                            <input type="url" wire:model="proxmoxUrl" placeholder="https://proxmox.example.com:8006" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">Token ID</label>
                            <input type="text" wire:model="proxmoxTokenId" placeholder="root@pam!abuseai" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <div style="font-size:10px; color:#6b7280; margin-top:2px;">Format: <code>user@realm!tokenname</code>.</div>
                        </div>
                        <div>
                            <label style="font-size:11px;">Token Secret</label>
                            <x-password-input wire:model="proxmoxTokenSecret" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                    </div>
                    <div style="font-size:10px; color:#b45309; margin-top:6px; line-height:1.55;">
                        Proxmox lookup walks every VM in <code>/cluster/resources</code> and grep-matches the IP against <code>net*</code>, <code>ipconfig*</code> and the description field. Capped at 2000 VMs per call. For larger clusters, layer Blesta or HostBill on top of Proxmox via a future combined provider.
                    </div>
                </div>
                @endif

                {{-- Blesta --}}
                @if($providerType === 'blesta')
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">Blesta Connection</div>
                    <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label style="font-size:11px;">API Base URL</label>
                            <input type="url" wire:model="blestaUrl" placeholder="https://billing.example.com/api" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">Admin URL (for direct links)</label>
                            <input type="url" wire:model="blestaAdminUrl" placeholder="https://billing.example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">API User</label>
                            <input type="text" wire:model="blestaApiUser" placeholder="abuse_api" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">API Key</label>
                            <x-password-input wire:model="blestaApiKey" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                    </div>
                </div>
                @endif

                {{-- HostBill --}}
                @if($providerType === 'hostbill')
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">HostBill Connection</div>
                    <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label style="font-size:11px;">API URL</label>
                            <input type="url" wire:model="hostbillUrl" placeholder="https://billing.example.com/admin/api.php" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">Admin URL (for direct links)</label>
                            <input type="url" wire:model="hostbillAdminUrl" placeholder="https://billing.example.com/admin" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">API ID</label>
                            <input type="text" wire:model="hostbillApiId" placeholder="abuse-api" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">API Key</label>
                            <x-password-input wire:model="hostbillApiKey" placeholder="{{ $editingBrandId ? 'Leave blank to keep' : '' }}" />
                        </div>
                    </div>
                </div>
                @endif

                {{-- Generic HTTP provider config (free-form JSON) --}}
                @if($providerType === 'generic_http')
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">Custom HTTP API</div>
                    <label style="font-size:11px;">Provider config (JSON)</label>
                    <textarea wire:model="genericProviderConfig" rows="12" style="width:100%; padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:11.5px; font-family:ui-monospace,monospace;" placeholder='{
  "lookup": {
    "method": "GET",
    "url": "https://billing.example.com/api/v1/services?ip={ip}",
    "headers": { "Authorization": "Bearer {token}" },
    "secrets": { "token": "REPLACE_ME" },
    "map": {
      "service_id": "data.0.id",
      "client_id": "data.0.customer.id",
      "hostname": "data.0.hostname",
      "status": "data.0.state",
      "registered_at": "data.0.created_at",
      "user_email": "data.0.customer.email"
    },
    "status_map": { "online": "active", "offline": "suspended" }
  },
  "actions": {
    "suspend": { "method": "POST", "url": "https://billing.example.com/api/v1/services/{service_id}/suspend", "body": { "reason": "{reason}" } },
    "unsuspend": { "method": "POST", "url": "https://billing.example.com/api/v1/services/{service_id}/unsuspend" }
  },
  "admin_url": "https://billing.example.com/admin/services/{service_id}"
}'></textarea>
                    @error('genericProviderConfig')
                        <div style="color:#dc2626; font-size:11px; margin-top:4px;">{{ $message }}</div>
                    @enderror
                    <div style="font-size:10px; color:#6b7280; margin-top:4px; line-height:1.55;">
                        Placeholders <code>{ip}</code>, <code>{service_id}</code>, <code>{client_id}</code>, <code>{reason}</code>, plus any keys under <code>lookup.secrets</code> are interpolated into URLs / headers / body. Mapping uses dot paths (<code>data.0.id</code>); use <code>__keep__</code> for any secret you want to leave unchanged.
                    </div>

                    <div style="margin-top:10px; padding-top:10px; border-top:1px dashed #e5e7eb;">
                        <label style="display:flex; align-items:flex-start; gap:8px; font-size:12px; cursor:pointer;">
                            <input type="checkbox" wire:model="autoTakedownUrls" style="margin-top:2px;">
                            <span>
                                <strong>Auto-takedown phishing URLs</strong>
                                <div style="font-size:10.5px; color:#6b7280; line-height:1.5; margin-top:2px;">
                                    When on, scored phishing cases for this brand automatically run the <code>url_takedown</code> lookup → DELETE flow for every reported URL whose host matches <code>host_patterns</code>. Requires a valid <code>url_takedown</code> block in the JSON above.
                                </div>
                            </span>
                        </label>
                    </div>
                </div>
                @endif

                {{-- Hostname Pattern --}}
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">VPS Hostname Matching</div>
                    <div>
                        <label style="font-size:11px;">Hostname Pattern</label>
                        <input type="text" wire:model="hostnamePattern" placeholder="example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        <div style="font-size:10px; color:#6b7280; margin-top:2px;">VPS hostnames containing this text belong to this brand. E.g. "example.com" matches "404319.EXAMPLE.COM". Comma-separate for multiple: "example.com, acme.net"</div>
                    </div>
                </div>

                {{-- Public Abuse Report Form --}}
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
                    <div style="font-size:12px; font-weight:600; color:#2563eb; margin-bottom:8px;">Public Abuse Report Form</div>

                    <div>
                        <label style="font-size:11px;">Report Hostnames</label>
                        <input type="text" wire:model="reportHostnames" placeholder="abuse.example.com, report.example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        <div style="font-size:10px; color:#6b7280; margin-top:2px;">Hostnames that should serve this brand's abuse form at <code>/report</code>. Comma- or newline-separated. Point DNS at the abuse server and add TLS certs separately.</div>
                    </div>

                    <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:8px;">
                        <div>
                            <label style="font-size:11px;">Page Title</label>
                            <input type="text" wire:model="reportPageTitle" placeholder="Report Abuse — Acme" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">Primary Color</label>
                            <input type="text" wire:model="reportPrimaryColor" placeholder="#2563eb" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px;">Support Email (form footer)</label>
                            <input type="email" wire:model="reportSupportEmail" placeholder="abuse@example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <div style="font-size:10px; color:#6b7280;">Falls back to the brand's Reply-To, then From email.</div>
                        </div>
                        <div>
                            <label style="font-size:11px;">Favicon URL</label>
                            <input type="url" wire:model="reportFaviconUrl" placeholder="https://example.com/favicon.ico" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <div style="font-size:10px; color:#6b7280;">Browser-tab icon for this brand's report page. Falls back to the brand's logo, then the default favicon.</div>
                        </div>
                    </div>

                    <div style="margin-top:8px;">
                        <label style="font-size:11px;">Intro Text</label>
                        <textarea wire:model="reportIntro" rows="2" placeholder="Use this form to report abusive activity originating from Acme's network." style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></textarea>
                    </div>

                    <div style="margin-top:8px;">
                        <label style="font-size:11px;">Footer Links</label>
                        <textarea wire:model="reportFooterLinks" rows="3" placeholder="Privacy Policy|https://example.com/privacy&#10;Terms of Service|https://example.com/terms" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:12px; font-family:monospace;"></textarea>
                        <div style="font-size:10px; color:#6b7280;">One per line. Format: <code>Label|https://url</code></div>
                    </div>

                    <div style="margin-top:8px;">
                        <label style="font-size:11px;">Footer HTML (optional)</label>
                        <textarea wire:model="reportFooterHtml" rows="3" placeholder="&lt;small&gt;Reports are processed within 24h. &lt;/small&gt;" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:12px; font-family:monospace;"></textarea>
                        <div style="font-size:10px; color:#6b7280;">Rendered raw under the form. Admins only — not user-submitted.</div>
                    </div>
                </div>

                <div style="margin-top:8px;"><label style="font-size:11px; display:flex; align-items:center; gap:4px;"><input type="checkbox" wire:model="isDefault"> Default brand</label></div>
                <div style="margin-top:14px;" class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ $editingBrandId ? 'Update' : 'Create' }}</button>
                    <button type="button" wire:click="resetBrandForm" class="btn btn-secondary btn-sm">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card" style="padding:0; overflow:hidden; margin-bottom:24px;">
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>From</th><th>Hostname Pattern</th><th>Provider</th><th>Connections</th><th>Default</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse($brands as $brand)
                    <tr>
                        <td><strong>{{ $brand->name }}</strong> <span class="text-muted">({{ $brand->slug }})</span></td>
                        <td class="text-sm">{{ $brand->from_name }} &lt;{{ $brand->from_email }}&gt;</td>
                        <td class="text-sm" style="font-family:monospace;">{{ $brand->hostname_pattern ?? '-' }}</td>
                        <td class="text-sm">
                            @php
                                $pt = $brand->provider_type instanceof \App\Enums\ProviderType
                                    ? $brand->provider_type
                                    : \App\Enums\ProviderType::tryFrom((string) ($brand->provider_type ?? 'none'));
                            @endphp
                            @if($pt && $pt !== \App\Enums\ProviderType::None)
                                <span class="badge badge-investigating" title="{{ $pt->description() }}">{{ $pt->label() }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $brand->email_connections_count }}</td>
                        <td>@if($brand->is_default) <span class="badge badge-resolved">Default</span> @endif</td>
                        <td class="flex gap-2">
                            <button wire:click="editBrand('{{ $brand->id }}')" class="btn btn-secondary btn-sm">Edit</button>
                            <button wire:click="deleteBrand('{{ $brand->id }}')" class="btn btn-danger btn-sm" onclick="return confirm('Delete brand \'{{ addslashes($brand->name) }}\'? This cannot be undone.')">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center; padding:2rem; color:#6b7280;">No brands. Create one to start sending emails.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- ===== EMAIL CONNECTIONS ===== --}}
    <div class="card-header" style="margin-bottom:0;">
        <div class="card-title">Email Connections (POP3/IMAP)</div>
        @if(! $showConnectionForm)
            <button wire:click="createConnection" class="btn btn-primary btn-sm">+ New Connection</button>
        @endif
    </div>

    @if($showConnectionForm)
        <div class="card" style="border:2px solid #2563eb;">
            <div class="card-title" style="margin-bottom:12px;">{{ $editingConnectionId ? 'Edit Connection' : 'New Connection' }}</div>
            <form wire:submit="saveConnection">
                <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div><label style="font-size:11px;">Name *</label><input type="text" wire:model="connName" placeholder="abuse@example.com inbox" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:11px;">Brand</label>
                        <select wire:model="connBrandId" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <option value="">— No brand —</option>
                            @foreach($brands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                        </select>
                    </div>
                    <div><label style="font-size:11px;">Protocol</label>
                        <select wire:model="connProtocol" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <option value="pop3">POP3</option><option value="imap">IMAP</option>
                        </select>
                    </div>
                    <div><label style="font-size:11px;">Host *</label><input type="text" wire:model="connHost" placeholder="mail.example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:11px;">Port</label><input type="number" wire:model="connPort" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:11px;">Username *</label><input type="text" wire:model="connUsername" placeholder="abuse@example.com" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                    <div>
                        <label style="font-size:11px;">Password {{ $editingConnectionId ? '(leave blank to keep)' : '*' }}</label>
                        <x-password-input wire:model="connPassword" />
                    </div>
                    <div><label style="font-size:11px;">Poll Interval (min)</label><input type="number" wire:model="connPollInterval" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;"></div>
                </div>
                @if($connProtocol === 'imap')
                    <div style="margin-top:12px;">
                        <label style="font-size:11px;">Folders to poll <span style="color:#6b7280;">(comma-separated; only existing folders are read)</span></label>
                        <input type="text" wire:model="connFolders" placeholder="INBOX, INBOX.Junk, INBOX.Spam, INBOX.spam, Junk, Spam, Junk Email, [Gmail]/Spam, Bulk Mail" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        <div style="font-size:11px; color:#6b7280; margin-top:4px;">
                            Common spam-folder names vary by provider: cPanel/Roundcube uses <code>INBOX.Junk</code>, Gmail uses <code>[Gmail]/Spam</code>, Outlook/Exchange uses <code>Junk Email</code>. Listing several is harmless — missing folders are skipped.
                        </div>
                    </div>
                @endif
                <div style="margin-top:8px; display:flex; gap:16px;">
                    <label style="font-size:12px; display:flex; align-items:center; gap:4px;"><input type="checkbox" wire:model="connSsl"> SSL</label>
                    <label style="font-size:12px; display:flex; align-items:center; gap:4px;"><input type="checkbox" wire:model="connAutoImport"> Auto-import emails</label>
                </div>
                <div style="margin-top:14px;" class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ $editingConnectionId ? 'Update' : 'Create' }}</button>
                    <button type="button" wire:click="resetConnectionForm" class="btn btn-secondary btn-sm">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card" style="padding:0; overflow:hidden; margin-bottom:24px;">
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>Brand</th><th>Server</th><th>Auto-import</th><th>Status</th><th>Last Polled</th><th style="min-width:200px;">Actions</th></tr></thead>
            <tbody>
                @forelse($connections as $conn)
                    <tr>
                        <td><strong>{{ $conn->name }}</strong></td>
                        <td>@if($conn->brand) <span class="badge badge-investigating">{{ $conn->brand->name }}</span> @else — @endif</td>
                        <td class="text-sm" style="font-family:monospace;">{{ $conn->protocol }}://{{ $conn->host }}:{{ $conn->port }}</td>
                        <td>{{ $conn->auto_import ? 'Every ' . $conn->poll_interval_minutes . 'min' : 'Manual' }}</td>
                        <td>
                            <button wire:click="toggleConnection('{{ $conn->id }}')"
                                aria-pressed="{{ $conn->is_active ? 'true' : 'false' }}"
                                onclick="return confirm('{{ $conn->is_active ? 'Disable' : 'Enable' }} mailbox connection \"{{ addslashes($conn->username) }}\"?')"
                                class="badge {{ $conn->is_active ? 'badge-resolved' : 'badge-closed' }}"
                                style="cursor:pointer; border:none;"
                                title="Click to toggle active state">
                                {{ $conn->is_active ? 'Active' : 'Disabled' }}
                            </button>
                        </td>
                        <td class="text-sm text-muted">
                            {{ $conn->last_polled_at?->diffForHumans() ?? 'Never' }}
                            @if($conn->last_message_count !== null)
                                <span style="margin-left:4px;">({{ $conn->last_message_count }} msgs)</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <button wire:click="testConnection('{{ $conn->id }}')" class="btn btn-secondary btn-sm"
                                    wire:loading.attr="disabled" wire:target="testConnection('{{ $conn->id }}')">
                                    <span wire:loading.remove wire:target="testConnection('{{ $conn->id }}')">Test</span>
                                    <span wire:loading wire:target="testConnection('{{ $conn->id }}')">...</span>
                                </button>
                                <a href="/admin/email-inbox?connection={{ $conn->id }}" class="btn btn-primary btn-sm">Inbox</a>
                                <button wire:click="editConnection('{{ $conn->id }}')" class="btn btn-secondary btn-sm">Edit</button>
                                <button wire:click="deleteConnection('{{ $conn->id }}')" class="btn btn-danger btn-sm" onclick="return confirm('Delete mailbox connection \'{{ addslashes($conn->username) }}\'? This cannot be undone.')">Del</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center; padding:2rem; color:#6b7280;">No email connections.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- ===== DROP TAGS (Email Filter Rules) ===== --}}
    <div class="card-header" style="margin-bottom:0;">
        <div class="card-title">Drop Tags</div>
        @if(! $showFilterForm)
            <button wire:click="createFilter" class="btn btn-primary btn-sm">+ New Drop Tag</button>
        @endif
    </div>

    <div class="text-sm text-muted" style="margin-bottom:8px;">
        Emails whose <strong>subject</strong> or <strong>body</strong> contains one of these keywords are dropped during ingestion — no report, no case, no ACK. Match is case-insensitive substring. Leave Brand empty to apply globally to every inbox.
    </div>

    @if($showFilterForm)
        <div class="card" style="border:2px solid #2563eb;">
            <div class="card-title" style="margin-bottom:12px;">{{ $editingFilterId ? 'Edit Drop Tag' : 'New Drop Tag' }}</div>
            <form wire:submit="saveFilter">
                <div class="resp-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="font-size:11px;">Pattern *</label>
                        <input type="text" wire:model="filterPattern" placeholder="e.g. newsletter, promotional, no-reply" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                        <div style="font-size:10px; color:#6b7280; margin-top:2px;">Case-insensitive. One pattern per rule.</div>
                    </div>
                    <div>
                        <label style="font-size:11px;">Match In *</label>
                        <select wire:model="filterMatchField" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <option value="any">Subject or Body</option>
                            <option value="subject">Subject only</option>
                            <option value="body">Body only</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px;">Brand (optional)</label>
                        <select wire:model="filterBrandId" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                            <option value="">— All brands (global) —</option>
                            @foreach($brands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                        </select>
                        <div style="font-size:10px; color:#6b7280; margin-top:2px;">Leave empty to apply to every inbox.</div>
                    </div>
                    <div>
                        <label style="font-size:11px;">Notes</label>
                        <input type="text" wire:model="filterNotes" placeholder="Why this is dropped" style="width:100%; padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                    </div>
                </div>
                <div style="margin-top:14px;" class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ $editingFilterId ? 'Update' : 'Create' }}</button>
                    <button type="button" wire:click="resetFilterForm" class="btn btn-secondary btn-sm">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Pattern</th><th>Match In</th><th>Scope</th><th>Notes</th><th>Status</th><th style="min-width:160px;">Actions</th></tr></thead>
            <tbody>
                @forelse($filterRules as $rule)
                    <tr>
                        <td><strong style="font-family:monospace;">{{ $rule->pattern }}</strong></td>
                        <td class="text-sm">
                            @if($rule->match_field === 'subject') Subject
                            @elseif($rule->match_field === 'body') Body
                            @else Subject or Body
                            @endif
                        </td>
                        <td class="text-sm">
                            @if($rule->brand)
                                <span class="badge badge-investigating">{{ $rule->brand->name }}</span>
                            @else
                                <span class="text-muted">All brands</span>
                            @endif
                        </td>
                        <td class="text-sm text-muted">{{ $rule->notes ?: '—' }}</td>
                        <td>
                            <button wire:click="toggleFilter('{{ $rule->id }}')"
                                aria-pressed="{{ $rule->is_active ? 'true' : 'false' }}"
                                class="badge {{ $rule->is_active ? 'badge-resolved' : 'badge-closed' }}"
                                style="cursor:pointer; border:none;"
                                title="Click to toggle">
                                {{ $rule->is_active ? 'Active' : 'Disabled' }}
                            </button>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <button wire:click="editFilter('{{ $rule->id }}')" class="btn btn-secondary btn-sm">Edit</button>
                                <button wire:click="deleteFilter('{{ $rule->id }}')" class="btn btn-danger btn-sm" onclick="return confirm('Delete drop tag \'{{ addslashes($rule->pattern) }}\'?')">Del</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:2rem; color:#6b7280;">No drop tags configured.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
