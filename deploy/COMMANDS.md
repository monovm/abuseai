# Abuse AI — Artisan Commands Reference

## Setup Commands

```bash
# Fresh install
php artisan migrate --force
php artisan db:seed
php artisan storage:link

# Clear all caches
php artisan optimize:clear
```

## Scheduled Commands (via `php artisan schedule:run`)

| Schedule      | Command                                              | Description                              |
|---------------|------------------------------------------------------|------------------------------------------|
| Every 5 min   | `php artisan abuse:poll-mailbox`                     | Poll IMAP mailbox for abuse emails       |
| Every 15 min  | `php artisan abuse:poll-feeds`                       | Poll AbuseIPDB API feed                  |
| Every 6 hours | `php artisan abuse:check-abuseipdb --stale=6 --min-score=25 --create-cases` | Scan IP inventory against AbuseIPDB |
| Twice daily   | `php artisan abuse:check-dnsbl --all --create-cases` | Check IPs against DNSBL blacklists       |

## Manual Commands

### Feed Polling
```bash
# Poll AbuseIPDB feed now
php artisan abuse:poll-feeds

# Poll IMAP mailbox now
php artisan abuse:poll-mailbox
```

### AbuseIPDB IP Checks
```bash
# Check all active IPs
php artisan abuse:check-abuseipdb --all

# Check specific IP
php artisan abuse:check-abuseipdb --ip=1.2.3.4

# Re-check stale IPs (not checked in 12 hours)
php artisan abuse:check-abuseipdb --stale=12

# Check and auto-create cases for score >= 25
php artisan abuse:check-abuseipdb --all --min-score=25 --create-cases
```

### DNSBL Blacklist Checks
```bash
# Check all IPs against all DNSBLs (CBL, PSBL, Spamhaus, Barracuda, SpamCop)
php artisan abuse:check-dnsbl --all

# Check specific IP
php artisan abuse:check-dnsbl --ip=1.2.3.4

# Check against specific DNSBL only
php artisan abuse:check-dnsbl --all --list=cbl

# Check and auto-create cases
php artisan abuse:check-dnsbl --all --create-cases
```

### Queue Management
```bash
# Start Horizon (manages all queues)
php artisan horizon

# Or start individual queue workers
php artisan queue:work redis --queue=ingestion
php artisan queue:work redis --queue=ai-triage
php artisan queue:work redis --queue=enrichment
php artisan queue:work redis --queue=case-engine
php artisan queue:work redis --queue=ai-intel
php artisan queue:work redis --queue=automation
php artisan queue:work redis --queue=ai-comms
php artisan queue:work redis --queue=notifications
php artisan queue:work redis --queue=default

# Retry failed jobs
php artisan queue:retry all

# View failed jobs
php artisan queue:failed
```

## Queue Architecture

| Queue          | Workers | Timeout | Jobs                                    |
|----------------|---------|---------|----------------------------------------|
| `ingestion`    | 4       | 60s     | Feed polling, email parsing, webhooks  |
| `ai-triage`    | 4       | 120s    | AI classify, noise filter, parse, translate |
| `enrichment`   | 8       | 30s     | VirusTotal, Shodan, DNSBL, GeoIP      |
| `case-engine`  | 4       | 60s     | Case creation, scoring, routing        |
| `ai-intel`     | 2       | 180s    | AI rescore, patterns, evidence analysis |
| `automation`   | 4       | 120s    | Suspend, block IP, escalate            |
| `ai-comms`     | 2       | 120s    | AI draft replies, notices, summaries   |
| `notifications`| 4       | 30s     | Email, Slack, PagerDuty               |
| `default`      | 2       | 60s     | Everything else                        |

**Note:** Case creation runs synchronously (not queued) to ensure cases are created immediately.
AI triage and enrichment are queued — they enhance cases in the background.
