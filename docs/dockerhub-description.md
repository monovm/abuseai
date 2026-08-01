# Abuse AI

**AI-powered abuse management platform for hosting companies and ISPs.**

Receives, triages, scores, and actions abuse reports with AI at every key
step. Built with Laravel 13 and Livewire, served by FrankenPHP (PHP 8.4),
powered by Claude or OpenAI.

- **Website:** https://abuseai.io
- **Source & full documentation:** https://github.com/monovm/abuseai
- **Docker guide:** https://github.com/monovm/abuseai/blob/main/docs/docker.md
- **Issue tracker:** https://github.com/monovm/abuseai/issues
- **Security policy:** https://github.com/monovm/abuseai/blob/main/SECURITY.md
- **License:** [Apache-2.0](https://github.com/monovm/abuseai/blob/main/LICENSE)

> Built by [MonoVM](https://monovm.com) — main sponsor of this project.

## Supported tags

| Tag | Meaning |
|-----|---------|
| `latest` | Most recent stable release |
| `1.2.3`, `1.2`, `1` | Semantic-version release tags |
| `edge` | Latest commit on `main` — may be ahead of the last release |

All images are multi-arch (`linux/amd64`, `linux/arm64`), built from the
repository's
[`Dockerfile`](https://github.com/monovm/abuseai/blob/main/Dockerfile) by
[GitHub Actions](https://github.com/monovm/abuseai/actions/workflows/docker-publish.yml),
and mirrored to GitHub Container Registry as
[`ghcr.io/monovm/abuseai`](https://github.com/monovm/abuseai/pkgs/container/abuseai).

## What is Abuse AI?

A centralized abuse desk that:

1. **Ingests** reports from email (POP3), a REST API, webhooks, a public web
   form, and feeds
2. **Classifies** them with AI — abuse type, severity, noise filtering, and
   translation of non-English reports
3. **Matches** target IPs against your IP inventory
4. **Creates cases** with severity scoring and SLA tracking
5. **Enriches** with threat intelligence (AbuseIPDB, VirusTotal, Shodan,
   DNSBL, Microsoft SNDS)
6. **Automates** actions — suspend, block, escalate, notify
7. **Communicates** via branded email with AI-drafted replies
8. **Monitors** IP reputation across 6 data sources continuously

It recognises 12 abuse types (spam, phishing, malware, DDoS, CSAM,
copyright/DMCA, fraud, law-enforcement requests, brute force, intrusion,
botnet/C2, other), switches between Claude and OpenAI from the admin panel,
and records every state change and AI call in an audit trail.

## What's in the image

One production image, three roles — selected by the `CONTAINER_ROLE`
environment variable:

| Role | `CONTAINER_ROLE` | Command | Purpose |
|------|------------------|---------|---------|
| Web | `app` (default) | image default | Web UI + REST API on port 80 |
| Queue worker | `horizon` | `php artisan horizon` | Processes all queues |
| Scheduler | `scheduler` | `php artisan schedule:work` | Cron loop: mailbox polling, reputation scans, SLA checks |

- **Listens on:** port `80` (plain HTTP — terminate TLS at your proxy)
- **Health check:** `GET /up` (built into the image)
- **Persistent data:** `/app/storage` — logs, evidence files, and the
  auto-generated `APP_KEY`
- **External services:** PostgreSQL 16 (or MySQL 8) and Redis 7

## Quick start with Docker Compose (recommended)

The repository ships a Compose file that runs the whole platform — web,
Horizon, scheduler, PostgreSQL, and Redis:

```bash
git clone https://github.com/monovm/abuseai.git
cd abuseai
cp .env.docker.example .env   # set DB_PASSWORD and your AI / threat-intel keys
docker compose up -d
```

Open http://localhost:8000. The first boot runs migrations automatically.

To start with a seeded admin login (`admin@abuseai.io` / `abuseai-demo`):

```bash
docker compose -f docker-compose.yml -f docker-compose.demo.yml up -d
```

The demo overlay is for **throwaway local use only** — never expose a seeded
host to an untrusted network.

## Standalone `docker run`

Bring your own PostgreSQL and Redis, put the connection settings in one env
file, and start the three roles from the same image:

```bash
# Shared settings — full reference:
# https://github.com/monovm/abuseai/blob/main/.env.docker.example
cat > abuseai.env <<'EOF'
APP_URL=http://localhost:8000
DB_CONNECTION=pgsql
DB_HOST=your-postgres
DB_PORT=5432
DB_DATABASE=abuseai
DB_USERNAME=abuseai
DB_PASSWORD=change-me
REDIS_HOST=your-redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
EOF

# Web UI + API
docker run -d --name abuseai-app \
  --env-file abuseai.env \
  -p 8000:80 \
  -v abuseai_storage:/app/storage \
  monovmcom/abuseai.io:latest

# Queue workers (Horizon)
docker run -d --name abuseai-horizon \
  --env-file abuseai.env \
  -e CONTAINER_ROLE=horizon \
  -v abuseai_storage:/app/storage \
  monovmcom/abuseai.io:latest php artisan horizon

# Scheduler
docker run -d --name abuseai-scheduler \
  --env-file abuseai.env \
  -e CONTAINER_ROLE=scheduler \
  -v abuseai_storage:/app/storage \
  monovmcom/abuseai.io:latest php artisan schedule:work
```

All three containers must share the same env file, database, Redis, and
`/app/storage` volume — the auto-generated `APP_KEY` is persisted there, and
containers with different keys cannot read each other's encrypted data.

Create your first admin user:

```bash
docker exec -it abuseai-app php artisan tinker
```

```php
$u = \App\Models\User::create([
    'name' => 'Admin',
    'email' => 'you@example.com',
    'password' => bcrypt('a-strong-password'),
]);
$u->assignRole('admin');
```

## Key environment variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_KEY` | auto-generated | Encryption key, persisted to `/app/storage` on first boot. Set a fixed `base64:…` value on ephemeral filesystems (PaaS) |
| `APP_URL` | — | Public URL, used for links in outbound email |
| `CONTAINER_ROLE` | `app` | `app`, `horizon`, or `scheduler` |
| `AUTO_MIGRATE` | `true` | Run database migrations on boot |
| `AUTO_SEED` | `false` | Seed roles, reference data, and an admin on boot |
| `DB_*` or `DB_URL` | — | PostgreSQL/MySQL connection (`DB_SSLMODE=require` for most managed databases) |
| `REDIS_*` or `REDIS_URL` | — | Redis for queues and cache |
| `AI_PROVIDER` | `claude` | `claude` or `openai`, with `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` |
| `ABUSEIPDB_KEY`, `VIRUSTOTAL_KEY`, `SHODAN_KEY`, `SNDS_KEY` | — | Threat-intel enrichment (all optional) |
| `SESSION_SECURE_COOKIE` | `false` | Set `true` once you serve over HTTPS |
| `TRUSTED_PROXIES` | — | Proxy IPs/CIDRs (or `*`) so reporter IPs and HTTPS detection are correct behind a proxy |

The complete, commented reference is
[`.env.docker.example`](https://github.com/monovm/abuseai/blob/main/.env.docker.example).

## Running behind HTTPS

The container serves plain HTTP on port 80. Terminate TLS at a reverse proxy
(nginx, Caddy, Cloudflare, a load balancer), then set `APP_URL` to your
`https://` address, `SESSION_SECURE_COOKIE=true`, and `TRUSTED_PROXIES` to
your proxy IPs.

## One-click cloud deploys

Deploy buttons for **Render**, **Heroku**, and **DigitalOcean App Platform** —
all building this same image — are in the
[GitHub README](https://github.com/monovm/abuseai#deploy-in-one-click), with
per-platform notes in the
[Docker guide](https://github.com/monovm/abuseai/blob/main/docs/docker.md#one-click--paas-deploys).

## License

[Apache License 2.0](https://github.com/monovm/abuseai/blob/main/LICENSE) —
free to use, modify, and redistribute, including for commercial and
managed-service use.

Found a security issue? Please follow the
[security policy](https://github.com/monovm/abuseai/blob/main/SECURITY.md)
instead of opening a public issue.
