# Deployment Guide

## System Requirements

| Component | Version | Purpose |
|---|---|---|
| PHP | 8.3+ | Application runtime |
| PostgreSQL | 16+ | Primary database |
| Redis | 7+ | Cache, queues, semaphores, rate limiting |
| Node.js | 18+ | Frontend asset compilation |
| Composer | 2.x | PHP dependency management |
| Supervisor | 4.x | Process management for Horizon workers |
| Nginx | 1.24+ | Reverse proxy (recommended) |

**Optional services:**
- Elasticsearch 8 for analytics and search
- MinIO or AWS S3 for evidence file storage
- MaxMind GeoLite2 for GeoIP lookups

---

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/your-org/abuseai.git /var/www/abuseai
cd /var/www/abuseai
```

### 2. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

### 3. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your settings (see Environment Configuration below).

### 4. Create Database

```bash
sudo -u postgres createuser abusedesk
sudo -u postgres createdb -O abusedesk abusedesk
```

### 5. Run Migrations

```bash
php artisan migrate
```

### 6. Create Admin User

```bash
php artisan make:user   # or use tinker:
php artisan tinker
> \App\Models\User::create(['name'=>'Admin','email'=>'admin@example.com','password'=>bcrypt('secret')]);
```

### 7. Set Permissions

```bash
chown -R www-data:www-data /var/www/abuseai
chmod -R 755 /var/www/abuseai/storage
chmod -R 755 /var/www/abuseai/bootstrap/cache
```

### 8. Optimize for Production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## Environment Configuration

### Core Settings

```dotenv
APP_NAME="AbuseDesk"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://abuse.yourdomain.com
```

### Database

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=abusedesk
DB_USERNAME=abusedesk
DB_PASSWORD=your-strong-password
```

### Redis

```dotenv
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

### Queue

```dotenv
QUEUE_CONNECTION=redis
HORIZON_PREFIX=abusedesk
```

### AI Providers

At least one AI provider must be configured:

```dotenv
# Active provider: 'claude' or 'openai'
AI_PROVIDER=claude

# Anthropic Claude
ANTHROPIC_API_KEY=sk-ant-...
CLAUDE_MODEL=claude-sonnet-4-20250514

# OpenAI (alternative)
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o
```

### Email (Mandrill)

```dotenv
MANDRILL_KEY=your-mandrill-api-key
```

### External Services

```dotenv
# AbuseIPDB
ABUSEIPDB_ENABLED=true
ABUSEIPDB_KEY=your-abuseipdb-key

# Threat Intelligence
VIRUSTOTAL_KEY=your-virustotal-key
SHODAN_KEY=your-shodan-key

# Microsoft SNDS
SNDS_KEY=your-snds-key

# DNSBL checking
DNSBL_ENABLED=true
```

### Webhook Secrets

```dotenv
WEBHOOK_SECRET_ABUSEIPDB=your-secret
WEBHOOK_SECRET_SPAMHAUS=your-secret
WEBHOOK_SECRET_GOOGLE_FBL=your-secret
WEBHOOK_SECRET_MICROSOFT_FBL=your-secret
WEBHOOK_SECRET_SPAMCOP=your-secret
```

### Infrastructure Integration

```dotenv
# WHMCS billing
WHMCS_API_URL=https://billing.yourdomain.com/includes/api.php
WHMCS_API_IDENTIFIER=your-identifier
WHMCS_API_SECRET=your-secret

# Virtualizor panel
VIRTUALIZOR_URL=https://panel.yourdomain.com:4085
VIRTUALIZOR_API_KEY=your-key
VIRTUALIZOR_API_PASS=your-pass
```

### CSAM Escalation

```dotenv
CSAM_LE_EMAIL=law-enforcement@example.gov
NCMEC_KEY=your-ncmec-key
```

### MaxMind GeoIP

```dotenv
MAXMIND_LICENSE_KEY=your-maxmind-key
```

---

## Queue Setup (Horizon)

Laravel Horizon manages queue workers. It requires Redis as the queue driver.

### Install Horizon Assets

```bash
php artisan horizon:install
php artisan horizon:publish
```

### Supervisor Configuration

Create `/etc/supervisor/conf.d/abusedesk-horizon.conf`:

```ini
[program:abusedesk-horizon]
process_name=%(program_name)s
command=php /var/www/abuseai/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/abuseai/storage/logs/horizon.log
stopwaitsecs=3600
```

Start the process:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start abusedesk-horizon
```

### Queue Definitions

Horizon is configured with 9 queues. The key queues and their timeout settings:

| Queue | Timeout | Purpose |
|---|---|---|
| `ingestion` | 60s | Feed polling, email parsing |
| `ai-triage` | 120s | AI classification calls |
| `enrichment` | 30s | GeoIP, WHOIS, threat intel |
| `case-engine` | 60s | Case creation and scoring |
| `ai-intel` | 180s | AI intelligence calls |
| `automation` | 120s | Suspend, block, escalate |
| `ai-comms` | 120s | AI email drafting |
| `notifications` | 30s | Outbound emails and alerts |
| `default` | 60s | General purpose |

### Monitoring Horizon

Access the Horizon dashboard at `/horizon` (requires authentication). It shows:
- Active workers per queue
- Job throughput and wait times
- Failed jobs with error details
- Recent job history

---

## Cron Setup (Scheduler)

Add the Laravel scheduler to your system crontab:

```bash
crontab -e
```

Add this line:

```
* * * * * cd /var/www/abuseai && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs these commands automatically:

| Command | Frequency | Purpose |
|---|---|---|
| `abuse:poll-mailbox` | Every 5 minutes | Poll IMAP connections for abuse emails |
| `abuse:poll-feeds` | Every 15 minutes | Poll AbuseIPDB and other feeds |
| `abuse:check-dnsbl` | Every 12 hours | Check IPs against DNS blacklists |
| `abuse:check-abuseipdb` | Periodic | Update AbuseIPDB scores for inventory IPs |
| `abuse:scan-reputation` | Periodic | Aggregate reputation scores from all sources |
| `abuse:prune-emails` | Daily at 03:30 | Delete raw email archives past the retention window |

### Storage retention

Every ingested email is written verbatim to `storage/app/private/emails/Y/m/*.eml`, and
binary attachments pulled out of it land in `storage/app/private/evidence/Y/m/`. Nothing
deleted them before `abuse:prune-emails` existed, so on a busy mailbox the archive grows
into the tens of gigabytes.

`abuse:prune-emails` deletes archives older than `EMAIL_ARCHIVE_RETENTION_DAYS` (default
`90`). Files attached to a report on a live case — `open`, `investigating`, or `actioned` —
are kept regardless of age.

```bash
# See what would go, and how much space it frees
php artisan abuse:prune-emails --days=90 --dry-run

# Apply it
php artisan abuse:prune-emails --days=90

# Include extracted binary attachments (PDFs, screenshots) too
php artisan abuse:prune-emails --days=90 --include-evidence
```

Pruned files stay listed in `abuse_reports.attachment_paths`. That is harmless by design:
the download route returns 404 for a missing file and the AI text extractor skips it, so a
stale entry degrades rather than breaks. What you do lose is the raw evidence for those
reports — re-triage and evidence analysis fall back to the email body alone. Pick a
retention window that outlives your dispute and legal-hold obligations.

---

## Email Polling

The `abuse:poll-mailbox` command polls configured IMAP connections for incoming abuse reports.

### Configuration

Email connections are configured in the admin UI under Brands. Each connection specifies:
- IMAP host, port, username, password, SSL setting
- Associated brand
- Auto-import toggle

### Manual Run

```bash
# Poll all active connections
php artisan abuse:poll-mailbox

# Poll a specific connection
php artisan abuse:poll-mailbox --connection=uuid-here

# Dry run (show what would be imported)
php artisan abuse:poll-mailbox --dry-run

# Skip ACK reply emails
php artisan abuse:poll-mailbox --no-ack
```

### What Happens During Polling

1. Emails are fetched from each active IMAP connection
2. Each email is checked for skip conditions (auto-replies, our own emails, duplicates)
3. Replies to existing cases are detected and added as follow-up notes
4. New emails are AI-classified to filter non-abuse content
5. Valid abuse reports create cases and link to the appropriate brand
6. ACK reply emails are sent to reporters via Mandrill
7. Processed emails are deleted from the IMAP server

---

## SSL / Reverse Proxy (Nginx)

Example Nginx configuration:

```nginx
server {
    listen 80;
    server_name abuse.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name abuse.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/abuse.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/abuse.yourdomain.com/privkey.pem;

    root /var/www/abuseai/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Max upload size for evidence files
    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## Monitoring

### Health Checks

Run the built-in configuration checker:

```bash
php artisan abusedesk:check-config
```

This validates:
- PostgreSQL connectivity
- Redis connectivity
- AI provider API key configuration
- Mandrill email key
- Storage directory writability
- Horizon configuration
- Active email connections count
- IP inventory loaded
- Brands configured
- WHMCS and Virtualizor connectivity

Example output:

```
AbuseDesk Configuration Check
==============================
+ Database: PostgreSQL connected (abusedesk)
+ Redis: Connected
+ AI Provider: Anthropic Claude (claude-sonnet-4-20250514) -- API key set
+ Mail: MANDRILL_KEY set
+ Storage: emails directory writable
+ Queue: Horizon configured
+ Email Connections: 3 active connections
+ IP Inventory: 1284 IPs loaded
+ Brands: 2 brands configured
  WHMCS: https://billing.yourdomain.com/includes/api.php (configured)
  Virtualizor: https://panel.yourdomain.com:4085 (configured)

Summary: 9 passed, 0 failed, 0 warnings
```

### Horizon Dashboard

Access `/horizon` for real-time queue monitoring. Check for:
- Failed jobs (investigate and retry)
- Queue wait times exceeding SLA thresholds
- Worker process health

### Application Logs

Logs are written to `storage/logs/laravel.log`. Key log entries:
- AI call failures and fallbacks
- Email polling results
- Case creation events
- Automation rule triggers
- Webhook signature failures

---

## Backup Strategy

### Database

Use `pg_dump` for PostgreSQL backups:

```bash
# Daily backup
pg_dump -U abusedesk -Fc abusedesk > /backups/abusedesk-$(date +%Y%m%d).dump

# Restore
pg_restore -U abusedesk -d abusedesk /backups/abusedesk-20260406.dump
```

### Evidence Files

Back up the local storage directory or configure S3 replication:

```bash
# Local storage backup
rsync -av /var/www/abuseai/storage/app/emails/ /backups/emails/
```

### Environment and Configuration

Keep `.env` and any custom configuration in a secure, versioned location outside the web root. Never commit `.env` to version control.

### Redis

If using Redis persistence (RDB/AOF), back up the dump files. Queue data is transient and does not need backup, but cache data (AI provider settings, rate limit counters) will be rebuilt automatically.

---

## Upgrading

### Standard Upgrade

```bash
cd /var/www/abuseai

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader
npm install
npm run build

# Run new migrations
php artisan migrate --force

# Clear and rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Restart queue workers
php artisan horizon:terminate
# Supervisor will auto-restart Horizon

# Publish Horizon assets if updated
php artisan horizon:publish
```

### Zero-Downtime Considerations

- Run `php artisan migrate --force` before deploying new code if migrations are backward-compatible
- Use `php artisan horizon:terminate` to gracefully stop workers (they finish current jobs before stopping)
- Queue workers will pick up new code after restart
- Cache clearing causes a brief period of uncached config reads

### Rollback

```bash
# Rollback last migration batch
php artisan migrate:rollback --force

# Revert code
git checkout previous-tag

# Rebuild caches
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan horizon:terminate
```
