<?php

namespace App\Livewire\Admin;

use App\Enums\ProviderType;
use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\EmailConnection;
use App\Models\EmailFilterRule;
use App\Services\Email\EmailTransportSettings;
use App\Services\Email\MandrillService;
use Livewire\Component;

class BrandManager extends Component
{
    /**
     * Default IMAP folder list shown to operators on new connections and on
     * Cancel. Hoisted to a constant so the property default and the reset
     * default can't drift apart again. Folders the server doesn't expose are
     * skipped at poll time, so listing every common name is harmless.
     */
    private const DEFAULT_IMAP_FOLDERS = 'INBOX, INBOX.Junk, INBOX.Spam, INBOX.spam, Junk, Spam, Junk Email, [Gmail]/Spam, Bulk Mail';

    // Brand form
    public ?string $editingBrandId = null;
    public string $brandName = '';
    public string $brandSlug = '';
    public string $fromName = '';
    public string $fromEmail = '';
    public string $replyToEmail = '';
    public string $emailSignature = '';
    public string $emailFooter = '';
    public string $logoUrl = '';
    public string $websiteUrl = '';
    public bool $isDefault = false;
    public string $mandrillKey = '';
    // SMTP fallback — the brand's own mailbox, used when Mandrill fails.
    // Blank fields are derived at send time from the brand's inbox connection.
    public string $smtpHost = '';
    public string $smtpPort = '';
    public string $smtpUsername = '';
    public string $smtpPassword = '';
    public string $smtpEncryption = '';
    // WHMCS
    public string $whmcsUrl = '';
    public string $whmcsAdminUrl = '';
    public string $whmcsIdentifier = '';
    public string $whmcsSecret = '';
    public string $whmcsTicketDepartmentId = '';
    public string $whmcsAdminUsername = '';
    public string $whmcsMatchStrategy = 'hostname_to_domain';
    // Virtualizor
    public string $vzUrl = '';
    public string $vzApiKey = '';
    public string $vzApiPass = '';
    // SolusVM
    public string $solusVmUrl = '';
    public string $solusVmApiId = '';
    public string $solusVmApiKey = '';
    // Proxmox
    public string $proxmoxUrl = '';
    public string $proxmoxTokenId = '';
    public string $proxmoxTokenSecret = '';
    // Blesta
    public string $blestaUrl = '';
    public string $blestaAdminUrl = '';
    public string $blestaApiUser = '';
    public string $blestaApiKey = '';
    // HostBill
    public string $hostbillUrl = '';
    public string $hostbillAdminUrl = '';
    public string $hostbillApiId = '';
    public string $hostbillApiKey = '';
    // Hostname matching
    public string $hostnamePattern = '';

    // Public Abuse Report Form (persisted as report_hostnames + report_config jsonb)
    public string $reportHostnames = '';
    public string $reportPageTitle = '';
    public string $reportPrimaryColor = '';
    public string $reportSupportEmail = '';
    public string $reportFaviconUrl = '';
    public string $reportIntro = '';
    public string $reportFooterLinks = '';
    public string $reportFooterHtml = '';

    // Provider mode (which integration runs IP -> service for this brand)
    public string $providerType = 'none';

    // Generic HTTP provider config (raw JSON the operator pastes in)
    public string $genericProviderConfig = '';

    // Auto URL takedown — when on, a per-brand AutomationRule is kept in
    // sync that fires the TakedownUrls job on scored phishing cases.
    public bool $autoTakedownUrls = false;

    public bool $showBrandForm = false;

    // Connection form
    public ?string $editingConnectionId = null;
    public string $connName = '';
    public string $connBrandId = '';
    public string $connProtocol = 'pop3';
    public string $connHost = '';
    public int $connPort = 995;
    public string $connUsername = '';
    public string $connPassword = '';
    public bool $connSsl = true;
    public string $connFolders = self::DEFAULT_IMAP_FOLDERS;
    public bool $connAutoImport = false;
    public int $connPollInterval = 5;
    public bool $showConnectionForm = false;

    // Drop-filter form (operator-defined keywords for dropping inbound email
    // before it ever becomes a report or case).
    public ?string $editingFilterId = null;
    public string $filterPattern = '';
    public string $filterMatchField = EmailFilterRule::FIELD_ANY;
    public string $filterBrandId = '';
    public string $filterNotes = '';
    public bool $showFilterForm = false;

    // Global email transport switch: when false, Mandrill is bypassed and
    // every brand sends from its own SMTP inbox. Flip off when Mandrill is
    // down but still accepting ("queued") mail it never actually delivers.
    public bool $mandrillEnabled = true;

    public function mount(): void
    {
        $this->mandrillEnabled = EmailTransportSettings::mandrillEnabled();
    }

    public function toggleMandrill(): void
    {
        $this->mandrillEnabled = ! $this->mandrillEnabled;
        EmailTransportSettings::setMandrillEnabled($this->mandrillEnabled);

        session()->flash('success', $this->mandrillEnabled
            ? 'Mandrill enabled. Brands fall back to their own SMTP only if Mandrill rejects a message.'
            : 'Mandrill disabled. All brands now send from their own SMTP inbox.');
    }

    public function createBrand(): void
    {
        $this->resetBrandForm();
        $this->showBrandForm = true;
    }

    public function editBrand(string $id): void
    {
        $brand = Brand::findOrFail($id);
        $this->editingBrandId = $brand->id;
        $this->brandName = $brand->name;
        $this->brandSlug = $brand->slug;
        $this->fromName = $brand->from_name;
        $this->fromEmail = $brand->from_email;
        $this->replyToEmail = $brand->reply_to_email ?? '';
        $this->emailSignature = $brand->email_signature ?? '';
        $this->emailFooter = $brand->email_footer ?? '';
        $this->logoUrl = $brand->logo_url ?? '';
        $this->websiteUrl = $brand->website_url ?? '';
        $this->isDefault = $brand->is_default;
        // Load URLs (non-sensitive)
        $this->mandrillKey = ''; // Never load back — leave blank to keep existing
        $this->smtpHost = $brand->smtp_config['smtp_host'] ?? '';
        $this->smtpPort = isset($brand->smtp_config['smtp_port']) ? (string) $brand->smtp_config['smtp_port'] : '';
        $this->smtpUsername = $brand->smtp_config['smtp_username'] ?? '';
        $this->smtpPassword = ''; // Never load back
        $this->smtpEncryption = $brand->smtp_config['smtp_encryption'] ?? '';
        $this->whmcsUrl = $brand->whmcs_config['url'] ?? '';
        $this->whmcsAdminUrl = $brand->whmcs_config['admin_url'] ?? '';
        $this->whmcsIdentifier = ''; // Never load back
        $this->whmcsSecret = '';     // Never load back
        $this->whmcsTicketDepartmentId = isset($brand->whmcs_config['ticket_department_id'])
            ? (string) $brand->whmcs_config['ticket_department_id']
            : '';
        $this->whmcsAdminUsername = (string) ($brand->whmcs_config['admin_username'] ?? '');
        $this->whmcsMatchStrategy = (string) ($brand->whmcs_config['match_strategy']
            ?? \App\Enums\WhmcsMatchStrategy::default()->value);
        $this->vzUrl = $brand->virtualizor_config['url'] ?? '';
        $this->vzApiKey = '';        // Never load back
        $this->vzApiPass = '';       // Never load back

        // SolusVM
        $this->solusVmUrl = $brand->solusvm_config['url'] ?? '';
        $this->solusVmApiId = '';     // Never load back
        $this->solusVmApiKey = '';    // Never load back
        // Proxmox
        $this->proxmoxUrl = $brand->proxmox_config['url'] ?? '';
        $this->proxmoxTokenId = $brand->proxmox_config['token_id'] ?? '';
        $this->proxmoxTokenSecret = '';  // Never load back
        // Blesta
        $this->blestaUrl = $brand->blesta_config['url'] ?? '';
        $this->blestaAdminUrl = $brand->blesta_config['admin_url'] ?? '';
        $this->blestaApiUser = $brand->blesta_config['api_user'] ?? '';
        $this->blestaApiKey = '';        // Never load back
        // HostBill
        $this->hostbillUrl = $brand->hostbill_config['url'] ?? '';
        $this->hostbillAdminUrl = $brand->hostbill_config['admin_url'] ?? '';
        $this->hostbillApiId = $brand->hostbill_config['api_id'] ?? '';
        $this->hostbillApiKey = '';      // Never load back

        $this->hostnamePattern = $brand->hostname_pattern ?? '';

        $hosts = is_array($brand->report_hostnames ?? null) ? $brand->report_hostnames : [];
        $this->reportHostnames = implode(', ', $hosts);
        $cfg = is_array($brand->report_config ?? null) ? $brand->report_config : [];
        $this->reportPageTitle = (string) ($cfg['page_title'] ?? '');
        $this->reportPrimaryColor = (string) ($cfg['primary_color'] ?? '');
        $this->reportSupportEmail = (string) ($cfg['support_email'] ?? '');
        $this->reportFaviconUrl = (string) ($cfg['favicon_url'] ?? '');
        $this->reportIntro = (string) ($cfg['intro'] ?? '');
        $this->reportFooterHtml = (string) ($cfg['footer_html'] ?? '');
        $links = is_array($cfg['footer_links'] ?? null) ? $cfg['footer_links'] : [];
        $this->reportFooterLinks = implode("\n", array_map(
            fn ($l) => trim(($l['label'] ?? '') . '|' . ($l['url'] ?? ''), '|'),
            $links,
        ));

        $this->providerType = ($brand->provider_type instanceof ProviderType)
            ? $brand->provider_type->value
            : ((string) ($brand->provider_type ?? 'none'));

        // Pretty-print the existing config for editing; never reveal
        // secrets in the form — operators paste a fresh blob to update.
        $existing = is_array($brand->generic_provider_config ?? null) ? $brand->generic_provider_config : [];
        $this->genericProviderConfig = empty($existing)
            ? ''
            : (string) json_encode($this->scrubGenericSecrets($existing), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->autoTakedownUrls = AutomationRule::where('name', $this->autoTakedownRuleName($brand->id))
            ->where('is_active', true)
            ->exists();

        $this->showBrandForm = true;
    }

    public function saveBrand(): void
    {
        $allowedProviderTypes = array_map(fn ($p) => $p->value, ProviderType::cases());

        $this->validate([
            'brandName' => ['required', 'string', 'max:255'],
            'brandSlug' => ['required', 'string', 'max:100'],
            'fromName' => ['required', 'string', 'max:255'],
            'fromEmail' => ['required', 'email'],
            'providerType' => ['required', 'string', 'in:' . implode(',', $allowedProviderTypes)],
        ]);

        $genericProviderConfig = null;
        if ($this->providerType === ProviderType::GenericHttp->value && trim($this->genericProviderConfig) !== '') {
            $decoded = json_decode($this->genericProviderConfig, true);
            if (! is_array($decoded)) {
                $this->addError('genericProviderConfig', 'Generic provider config must be valid JSON.');
                return;
            }
            // Merge with existing so secrets the operator left out are preserved.
            $existing = $this->editingBrandId
                ? (Brand::find($this->editingBrandId)?->generic_provider_config ?? [])
                : [];
            $genericProviderConfig = $this->mergeGenericSecrets($existing, $decoded);
        }

        $data = [
            'name' => $this->brandName,
            'slug' => $this->brandSlug,
            'from_name' => $this->fromName,
            'from_email' => $this->fromEmail,
            'reply_to_email' => $this->replyToEmail ?: null,
            'email_signature' => $this->emailSignature ?: null,
            'email_footer' => $this->emailFooter ?: null,
            'logo_url' => $this->logoUrl ?: null,
            'website_url' => $this->websiteUrl ?: null,
            'is_default' => $this->isDefault,
            'smtp_config' => $this->buildSmtpConfig(),
            'whmcs_config' => $this->buildSecretConfig('whmcs_config', [
                'url' => $this->whmcsUrl,
                'admin_url' => $this->whmcsAdminUrl,
                'api_identifier' => $this->whmcsIdentifier,
                'api_secret' => $this->whmcsSecret,
                'ticket_department_id' => $this->whmcsTicketDepartmentId !== ''
                    ? (int) $this->whmcsTicketDepartmentId
                    : null,
                'admin_username' => $this->whmcsAdminUsername !== ''
                    ? $this->whmcsAdminUsername
                    : null,
                'match_strategy' => \App\Enums\WhmcsMatchStrategy::tryFrom($this->whmcsMatchStrategy)?->value
                    ?? \App\Enums\WhmcsMatchStrategy::default()->value,
            ]),
            'virtualizor_config' => $this->buildSecretConfig('virtualizor_config', [
                'url' => $this->vzUrl,
                'api_key' => $this->vzApiKey,
                'api_pass' => $this->vzApiPass,
            ]),
            'solusvm_config' => $this->buildSecretConfig('solusvm_config', [
                'url' => $this->solusVmUrl,
                'api_id' => $this->solusVmApiId,
                'api_key' => $this->solusVmApiKey,
            ]),
            'proxmox_config' => $this->buildSecretConfig('proxmox_config', [
                'url' => $this->proxmoxUrl,
                'token_id' => $this->proxmoxTokenId,
                'token_secret' => $this->proxmoxTokenSecret,
            ]),
            'blesta_config' => $this->buildSecretConfig('blesta_config', [
                'url' => $this->blestaUrl,
                'admin_url' => $this->blestaAdminUrl,
                'api_user' => $this->blestaApiUser,
                'api_key' => $this->blestaApiKey,
            ]),
            'hostbill_config' => $this->buildSecretConfig('hostbill_config', [
                'url' => $this->hostbillUrl,
                'admin_url' => $this->hostbillAdminUrl,
                'api_id' => $this->hostbillApiId,
                'api_key' => $this->hostbillApiKey,
            ]),
            'hostname_pattern' => $this->hostnamePattern ?: null,
            'provider_type' => $this->providerType,
            'generic_provider_config' => $genericProviderConfig,
            'report_hostnames' => $this->parseReportHostnames($this->reportHostnames),
            'report_config' => $this->buildReportConfig(),
        ];

        if ($this->isDefault) {
            Brand::where('is_default', true)->update(['is_default' => false]);
        }

        if ($this->editingBrandId) {
            $brand = Brand::findOrFail($this->editingBrandId);
            $brand->update($data);
            session()->flash('success', 'Brand updated.');
        } else {
            $brand = Brand::create($data);
            session()->flash('success', 'Brand created.');
        }

        $this->syncAutoTakedownRule($brand);

        $this->resetBrandForm();
    }

    public function deleteBrand(string $id): void
    {
        Brand::findOrFail($id)->delete();
        session()->flash('success', 'Brand deleted.');
    }

    public function testMandrill(MandrillService $mandrill): void
    {
        $result = $mandrill->ping();
        if ($result['success']) {
            session()->flash('success', 'Mandrill connected: ' . ($result['response'] ?? 'OK'));
        } else {
            session()->flash('error', 'Mandrill failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    // Connection methods
    public function createConnection(): void
    {
        $this->resetConnectionForm();
        $this->showConnectionForm = true;
    }

    public function editConnection(string $id): void
    {
        $conn = EmailConnection::findOrFail($id);
        $this->editingConnectionId = $conn->id;
        $this->connName = $conn->name;
        $this->connBrandId = $conn->brand_id ?? '';
        $this->connProtocol = $conn->protocol;
        $this->connHost = $conn->host;
        $this->connPort = $conn->port;
        $this->connUsername = $conn->username;
        $this->connPassword = '';
        $this->connSsl = $conn->ssl;
        $this->connFolders = implode(', ', $conn->folders ?? ['INBOX']);
        $this->connAutoImport = $conn->auto_import;
        $this->connPollInterval = $conn->poll_interval_minutes;
        $this->showConnectionForm = true;
    }

    public function saveConnection(): void
    {
        $this->validate([
            'connName' => ['required', 'string', 'max:255'],
            'connHost' => ['required', 'string'],
            'connUsername' => ['required', 'string'],
        ]);

        $data = [
            'name' => $this->connName,
            'brand_id' => $this->connBrandId ?: null,
            'protocol' => $this->connProtocol,
            'host' => $this->connHost,
            'port' => $this->connPort,
            'username' => $this->connUsername,
            'ssl' => $this->connSsl,
            'folders' => $this->connProtocol === 'imap'
                ? $this->parseFolderList($this->connFolders)
                : null,
            'auto_import' => $this->connAutoImport,
            'poll_interval_minutes' => $this->connPollInterval,
        ];

        if ($this->connPassword) {
            $data['password'] = $this->connPassword;
        }

        if ($this->editingConnectionId) {
            EmailConnection::findOrFail($this->editingConnectionId)->update($data);
            session()->flash('success', 'Connection updated.');
        } else {
            $data['password'] = $this->connPassword;
            EmailConnection::create($data);
            session()->flash('success', 'Connection created.');
        }

        $this->resetConnectionForm();
    }

    public function deleteConnection(string $id): void
    {
        EmailConnection::findOrFail($id)->delete();
        session()->flash('success', 'Connection deleted.');
    }

    public function toggleConnection(string $id): void
    {
        $conn = EmailConnection::findOrFail($id);
        $conn->update(['is_active' => ! $conn->is_active]);
    }

    public function testConnection(string $id): void
    {
        $conn = EmailConnection::findOrFail($id);

        $mailbox = app(\App\Services\Ingestion\MailboxClient::class);
        $folder = $conn->pollableFolders()[0] ?? null;
        $result = $mailbox->testConnection($conn, $folder);

        if ($result['success']) {
            $conn->update(['last_polled_at' => now(), 'last_message_count' => $result['messages']]);
            $sizeNote = ($result['size_bytes'] ?? 0) > 0
                ? ' (' . number_format($result['size_bytes'] / 1024, 1) . ' KB)'
                : '';
            $folderNote = $conn->protocol === 'imap' && $folder ? " in {$folder}" : '';
            session()->flash('success', "Connected to {$conn->name}: {$result['messages']} messages{$folderNote}{$sizeNote}");
        } else {
            session()->flash('error', "Connection failed for {$conn->name}: " . ($result['error'] ?? 'Unknown error'));
        }
    }

    public function resetBrandForm(): void
    {
        $this->editingBrandId = null;
        $this->brandName = $this->brandSlug = $this->fromName = $this->fromEmail = '';
        $this->replyToEmail = $this->emailSignature = $this->emailFooter = '';
        $this->logoUrl = $this->websiteUrl = $this->mandrillKey = '';
        $this->smtpHost = $this->smtpPort = $this->smtpUsername = $this->smtpPassword = $this->smtpEncryption = '';
        $this->whmcsUrl = $this->whmcsAdminUrl = $this->whmcsIdentifier = $this->whmcsSecret = '';
        $this->whmcsTicketDepartmentId = '';
        $this->whmcsMatchStrategy = \App\Enums\WhmcsMatchStrategy::default()->value;
        $this->vzUrl = $this->vzApiKey = $this->vzApiPass = '';
        $this->solusVmUrl = $this->solusVmApiId = $this->solusVmApiKey = '';
        $this->proxmoxUrl = $this->proxmoxTokenId = $this->proxmoxTokenSecret = '';
        $this->blestaUrl = $this->blestaAdminUrl = $this->blestaApiUser = $this->blestaApiKey = '';
        $this->hostbillUrl = $this->hostbillAdminUrl = $this->hostbillApiId = $this->hostbillApiKey = '';
        $this->hostnamePattern = '';
        $this->reportHostnames = $this->reportPageTitle = $this->reportPrimaryColor = '';
        $this->reportSupportEmail = $this->reportFaviconUrl = $this->reportIntro = '';
        $this->reportFooterLinks = $this->reportFooterHtml = '';
        $this->providerType = 'none';
        $this->genericProviderConfig = '';
        $this->autoTakedownUrls = false;
        $this->isDefault = false;
        $this->showBrandForm = false;
    }

    /**
     * Comma- or newline-separated list of hostnames → array of trimmed
     * lowercase hosts. Returns null when empty so the column stays nullable
     * instead of holding an empty array.
     */
    protected function parseReportHostnames(string $input): ?array
    {
        $parts = preg_split('/[\r\n,]+/', $input) ?: [];
        $hosts = [];
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p !== '' && ! in_array($p, $hosts, true)) {
                $hosts[] = $p;
            }
        }
        return $hosts ?: null;
    }

    /**
     * Build the report_config jsonb payload from the form fields. Keys are
     * only emitted when set, so we don't store empty strings the consumers
     * would have to defend against.
     */
    protected function buildReportConfig(): ?array
    {
        $cfg = array_filter([
            'page_title' => trim($this->reportPageTitle),
            'primary_color' => trim($this->reportPrimaryColor),
            'support_email' => trim($this->reportSupportEmail),
            'favicon_url' => trim($this->reportFaviconUrl),
            'intro' => trim($this->reportIntro),
            'footer_html' => trim($this->reportFooterHtml),
        ], fn ($v) => $v !== '');

        $links = [];
        foreach (preg_split('/[\r\n]+/', $this->reportFooterLinks) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($label !== '' && $url !== '') {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }
        if ($links) {
            $cfg['footer_links'] = $links;
        }

        return $cfg ?: null;
    }

    /**
     * Replace string values that look like secrets with a placeholder so
     * the operator never sees them re-rendered in the form. Pairs with
     * mergeGenericSecrets() on save.
     */
    /**
     * Deterministic name for the per-brand auto-takedown rule. Using the
     * brand id keeps the lookup idempotent across rename / re-save and
     * lets us toggle the rule without searching by content.
     */
    protected function autoTakedownRuleName(string $brandId): string
    {
        return 'auto_takedown_urls:' . $brandId;
    }

    /**
     * Create / update / deactivate the auto-takedown AutomationRule for
     * this brand to match $this->autoTakedownUrls. Off doesn't delete
     * the row — we just flip is_active so the trigger_count history
     * stays around for audit.
     */
    protected function syncAutoTakedownRule(Brand $brand): void
    {
        $name = $this->autoTakedownRuleName($brand->id);
        $existing = AutomationRule::where('name', $name)->first();

        if (! $this->autoTakedownUrls) {
            $existing?->update(['is_active' => false]);
            return;
        }

        $attributes = [
            'name' => $name,
            'is_active' => true,
            'trigger_event' => 'score_changed',
            'abuse_types' => ['phishing'],
            'conditions' => [
                ['field' => 'brand_id', 'operator' => '=', 'value' => $brand->id],
            ],
            'actions' => [['type' => 'takedown_urls']],
            'priority' => 50,
        ];

        if ($existing) {
            $existing->update($attributes);
        } else {
            AutomationRule::create($attributes);
        }
    }

    protected function scrubGenericSecrets(array $config): array
    {
        $secrets = $config['lookup']['secrets'] ?? null;
        if (is_array($secrets)) {
            foreach ($secrets as $k => $_) {
                $config['lookup']['secrets'][$k] = '__keep__';
            }
        }
        return $config;
    }

    /**
     * Apply secrets-preservation: any value the operator left as
     * "__keep__" is restored from the existing config so partial edits
     * don't blow away credentials.
     */
    protected function mergeGenericSecrets(array $existing, array $incoming): array
    {
        $existingSecrets = $existing['lookup']['secrets'] ?? [];
        $incomingSecrets = $incoming['lookup']['secrets'] ?? [];

        if (is_array($incomingSecrets)) {
            foreach ($incomingSecrets as $k => $v) {
                if ($v === '__keep__' && isset($existingSecrets[$k])) {
                    $incoming['lookup']['secrets'][$k] = $existingSecrets[$k];
                }
            }
        }
        return $incoming;
    }

    /**
     * smtp_config holds both the per-brand Mandrill key and the SMTP fallback
     * settings. Secrets (mandrill_key, smtp_password) keep their stored value
     * when left blank; the visible fields save exactly what's in the form so
     * operators can clear an override and go back to inbox-derived defaults.
     */
    protected function buildSmtpConfig(): ?array
    {
        $existing = [];
        if ($this->editingBrandId) {
            $existing = Brand::find($this->editingBrandId)?->smtp_config ?? [];
        }

        $cfg = [];

        $mandrillKey = $this->mandrillKey !== '' ? $this->mandrillKey : ($existing['mandrill_key'] ?? null);
        if (! empty($mandrillKey)) {
            $cfg['mandrill_key'] = $mandrillKey;
        }

        $smtpPassword = $this->smtpPassword !== '' ? $this->smtpPassword : ($existing['smtp_password'] ?? null);
        if (! empty($smtpPassword)) {
            $cfg['smtp_password'] = $smtpPassword;
        }

        if (trim($this->smtpHost) !== '') {
            $cfg['smtp_host'] = trim($this->smtpHost);
        }
        if (trim($this->smtpPort) !== '' && (int) $this->smtpPort > 0) {
            $cfg['smtp_port'] = (int) $this->smtpPort;
        }
        if (trim($this->smtpUsername) !== '') {
            $cfg['smtp_username'] = trim($this->smtpUsername);
        }
        if (in_array($this->smtpEncryption, ['ssl', 'tls', 'none'], true)) {
            $cfg['smtp_encryption'] = $this->smtpEncryption;
        }

        return $cfg ?: null;
    }

    /**
     * Merge new config with existing — only update secrets if user typed a new value.
     * Empty secret fields preserve the existing value from database.
     */
    protected function buildSecretConfig(string $configKey, array $newValues): ?array
    {
        // Get existing config from brand being edited
        $existing = [];
        if ($this->editingBrandId) {
            $brand = Brand::find($this->editingBrandId);
            $existing = $brand?->{$configKey} ?? [];
        }

        $merged = [];
        $hasAnyValue = false;

        foreach ($newValues as $key => $value) {
            if (! empty($value)) {
                $merged[$key] = $value;
                $hasAnyValue = true;
            } elseif (isset($existing[$key]) && ! empty($existing[$key])) {
                // Preserve existing secret value
                $merged[$key] = $existing[$key];
                $hasAnyValue = true;
            }
        }

        return $hasAnyValue ? $merged : null;
    }

    public function createFilter(): void
    {
        $this->resetFilterForm();
        $this->showFilterForm = true;
    }

    public function editFilter(string $id): void
    {
        $rule = EmailFilterRule::findOrFail($id);
        $this->editingFilterId = $rule->id;
        $this->filterPattern = $rule->pattern;
        $this->filterMatchField = $rule->match_field ?: EmailFilterRule::FIELD_ANY;
        $this->filterBrandId = $rule->brand_id ?? '';
        $this->filterNotes = $rule->notes ?? '';
        $this->showFilterForm = true;
    }

    public function saveFilter(): void
    {
        $this->validate([
            'filterPattern' => ['required', 'string', 'max:255'],
            'filterMatchField' => ['required', 'in:subject,body,any'],
            'filterNotes' => ['nullable', 'string', 'max:255'],
        ]);

        $data = [
            'pattern' => trim($this->filterPattern),
            'match_field' => $this->filterMatchField,
            'brand_id' => $this->filterBrandId ?: null,
            'notes' => trim($this->filterNotes) ?: null,
        ];

        if ($this->editingFilterId) {
            EmailFilterRule::findOrFail($this->editingFilterId)->update($data);
            session()->flash('success', 'Drop rule updated.');
        } else {
            EmailFilterRule::create($data + ['is_active' => true]);
            session()->flash('success', 'Drop rule created.');
        }

        $this->resetFilterForm();
    }

    public function deleteFilter(string $id): void
    {
        EmailFilterRule::findOrFail($id)->delete();
        session()->flash('success', 'Drop rule deleted.');
    }

    public function toggleFilter(string $id): void
    {
        $rule = EmailFilterRule::findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
    }

    public function resetFilterForm(): void
    {
        $this->editingFilterId = null;
        $this->filterPattern = '';
        $this->filterMatchField = EmailFilterRule::FIELD_ANY;
        $this->filterBrandId = '';
        $this->filterNotes = '';
        $this->showFilterForm = false;
    }

    public function resetConnectionForm(): void
    {
        $this->editingConnectionId = null;
        $this->connName = $this->connHost = $this->connUsername = $this->connPassword = '';
        $this->connBrandId = '';
        $this->connProtocol = 'pop3';
        $this->connPort = 995;
        $this->connSsl = true;
        $this->connFolders = self::DEFAULT_IMAP_FOLDERS;
        $this->connAutoImport = false;
        $this->connPollInterval = 5;
        $this->showConnectionForm = false;
    }

    /**
     * Split a user-entered comma/newline-separated folder list into a clean
     * array. Drops blanks and dedups while preserving order so the user sees
     * the folders polled in the same order they wrote them.
     *
     * @return array<int, string>|null
     */
    protected function parseFolderList(string $input): ?array
    {
        $parts = preg_split('/[\r\n,]+/', $input) ?: [];
        $folders = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && ! in_array($p, $folders, true)) {
                $folders[] = $p;
            }
        }
        return $folders ?: null;
    }

    public function render()
    {
        return view('livewire.admin.brand-manager', [
            'brands' => Brand::withCount('emailConnections')->get(),
            'connections' => EmailConnection::with('brand')->get(),
            'filterRules' => EmailFilterRule::with('brand')->orderByDesc('is_active')->orderBy('pattern')->get(),
            'mandrillConfigured' => app(MandrillService::class)->isConfigured(),
        ]);
    }
}
