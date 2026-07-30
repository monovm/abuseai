# Running Abuse AI with Docker

The Docker image bundles PHP 8.4 (FrankenPHP), the compiled frontend, and
all dependencies. `docker compose` adds PostgreSQL and Redis so the whole
platform — web UI, queue workers, and scheduler — runs with one command.

## Quick start

```bash
git clone https://github.com/monovm/abuseai.git
cd abuseai

cp .env.docker.example .env
# Edit .env: set DB_PASSWORD and your AI / threat-intel API keys.

docker compose up -d
```

Open <http://localhost:8000>. The first boot runs migrations automatically,
so the database is empty — create an admin user (see below) or start with
the demo overlay.

### Try the demo (seeded data)

```bash
docker compose -f docker-compose.yml -f docker-compose.demo.yml up -d
```

This seeds roles, reference data and an admin login: `admin@abuseai.io` /
`abuseai-demo`. The demo overlay is for **throwaway local use only** — never
expose a seeded host to an untrusted network.

Sample reporters, customers and abuse cases are **not** included: they are
generated with model factories, which need `fakerphp/faker` — a dev dependency
that is absent from the production image. From a development checkout you can
add them with `php artisan db:seed --class=DemoDataSeeder`.

Without the overlay the database is not seeded; create an admin as below.

### Create your own admin (non-demo)

```bash
docker compose exec app php artisan tinker
```

```php
$u = \App\Models\User::create([
    'name' => 'Admin',
    'email' => 'you@example.com',
    'password' => bcrypt('a-strong-password'),
]);
$u->assignRole('admin');
```

## What runs

| Service     | Role                              | Image                |
|-------------|-----------------------------------|----------------------|
| `app`       | Web UI + API (FrankenPHP on :80)  | `monovmcom/abuseai.io` |
| `horizon`   | Queue workers (all 9 queues)      | `monovmcom/abuseai.io` |
| `scheduler` | Cron loop (`schedule:work`)       | `monovmcom/abuseai.io` |
| `db`        | PostgreSQL 16                     | `postgres:16-alpine` |
| `redis`     | Queue + cache backend             | `redis:7-alpine`     |

`app`, `horizon`, and `scheduler` are the **same image** — the role is
selected by the `CONTAINER_ROLE` environment variable.

## Configuration

All settings come from `.env` (copied from `.env.docker.example`). The same
file is read by the containers and by Compose itself.

| Variable       | Purpose                                                       |
|----------------|---------------------------------------------------------------|
| `APP_KEY`      | Leave blank — generated and persisted to `storage/` on boot.  |
| `APP_PORT`     | Host port for the web UI (default `8000`).                    |
| `AUTO_MIGRATE` | Run migrations on boot (default `true`).                      |
| `AUTO_SEED`    | Seed roles, admin and reference data on boot (default `false`).|
| `SESSION_SECURE_COOKIE` | Must be `false` for local HTTP, `true` behind HTTPS. |

For a fixed application key instead of the auto-generated one:

```bash
docker compose run --rm app php artisan key:generate --show
# copy the base64:... value into APP_KEY in .env
```

## Running behind HTTPS

The container serves plain HTTP on port 80; terminate TLS at a reverse
proxy (nginx, Caddy, Cloudflare, a load balancer). Then in `.env`:

- set `APP_URL` to your `https://` address,
- set `SESSION_SECURE_COOKIE=true`,
- set `TRUSTED_PROXIES` to your proxy IPs (or `*` if the proxy is the only
  ingress) so `request()->ip()` and HTTPS detection are correct.

## Using a prebuilt image

Published images are available without building locally:

```bash
docker pull monovmcom/abuseai.io:latest          # Docker Hub
docker pull ghcr.io/monovm/abuseai:latest  # GitHub Container Registry
```

Both are multi-arch (`linux/amd64`, `linux/arm64`).

## Common operations

```bash
docker compose logs -f app          # tail web logs
docker compose ps                   # service status
docker compose exec app php artisan abuse:poll-mailbox   # run a command
docker compose exec app php artisan horizon:status
docker compose down                 # stop (keeps volumes/data)
docker compose down -v              # stop and DELETE all data
```

Data lives in named volumes: `pgdata` (database), `redisdata` (queues),
and `storage` (logs, evidence files, the generated `APP_KEY`).

## Updating

```bash
git pull
docker compose pull        # or: docker compose build
docker compose up -d
```

Migrations run automatically on the `app` container's next boot.

## One-click & PaaS deploys

### Render

The repo ships a [`render.yaml`](../render.yaml) Blueprint. Use the
**Deploy to Render** button in the README, or in the Render dashboard
choose *New → Blueprint* and point it at this repo. Render provisions the
web app, a Horizon worker (queue + scheduler), PostgreSQL, and Redis.

Render prompts for the `sync: false` values during deploy — at minimum:

- `APP_KEY` — generate with
  `php -r "echo 'base64:'.base64_encode(random_bytes(32));"`
- `APP_URL` — your `https://…onrender.com` service URL
- AI / threat-intel keys (optional — can be added afterwards)

The free Postgres/Redis plans suit evaluation; the web and worker
services need a paid instance plan.

### Heroku

The repo ships [`app.json`](../app.json) and [`heroku.yml`](../heroku.yml), so
the **Deploy to Heroku** button in the README builds this project's Dockerfile
on Heroku's container stack — no buildpack, no second build definition.

What the button provisions:

| Piece | Detail |
|-------|--------|
| `web` dyno | FrankenPHP, bound to Heroku's `$PORT` |
| `worker` dyno | `schedule:work` + `horizon` in one dyno |
| Heroku Postgres | exports `DATABASE_URL`, aliased to `DB_URL` on boot |
| Heroku Key-Value Store | exports `REDIS_URL` (`rediss://`, TLS) |
| Release phase | `php artisan migrate --force` |
| Post-deploy | `php artisan db:seed --force` (roles, reference data, admin) |

Prompted values:

- `APP_KEY` — **required**. Generate with
  `php -r "echo 'base64:'.base64_encode(random_bytes(32));"`. It cannot be
  auto-generated on Heroku: a dyno's filesystem is wiped on every restart, and
  a key that changes makes existing sessions and encrypted columns unreadable.
- `SEED_ADMIN_PASSWORD` — optional. Leave blank and the seeder prints a random
  password once; read it with `heroku logs --tail`. Change it after first login
  either way.
- API keys — all optional, addable later.

After the first deploy, set `APP_URL` to the app's real URL — Heroku only
assigns it at creation time, and queued jobs and outgoing email need it to
build absolute links:

```bash
heroku config:set APP_URL=https://your-app.herokuapp.com -a your-app
```

Notes specific to Heroku:

- **No free tier.** Both dynos and both add-ons are paid.
- Migrations deliberately run in the *release phase* rather than from the
  container entrypoint (`AUTO_MIGRATE=false`). Web and worker dynos boot in
  parallel, and two concurrent `migrate --force` runs can collide.
- Heroku Key-Value Store serves TLS with a self-signed certificate, so
  `REDIS_TLS_INSECURE=true` is set to relax peer verification. The connection
  stays encrypted; only the certificate check is skipped.
- Dynos run as an unprivileged user, so `XDG_DATA_HOME` and `XDG_CONFIG_HOME`
  point at `/tmp` to give Caddy a writable state directory.
- The filesystem is ephemeral. Evidence files and logs do not survive a
  restart — configure S3 (`AWS_*` in `.env.docker.example`) for anything you
  need to keep.

### DigitalOcean App Platform

[`.do/deploy.template.yaml`](../.do/deploy.template.yaml) powers the **Deploy to
DigitalOcean** button and builds the same Dockerfile, provisioning a `web`
service, a `worker`, and a dev PostgreSQL database.

**One prerequisite the button cannot cover:** App Platform's dev databases are
PostgreSQL-only, and the Deploy button supports nothing else. Both Horizon and
the report de-duplicator talk to Redis directly, so:

1. In the DigitalOcean control panel, *Databases → Create → Valkey*.
2. Copy its connection string (`rediss://default:…@…:25061`).
3. Click the button and paste it as `REDIS_URL` when prompted, along with an
   `APP_KEY`.

`REDIS_TLS_INSECURE=true` is preset because managed Valkey's certificate is not
in the image's trust store. Drop it if you point `REDIS_URL` at an endpoint with
a verifiable certificate.

The `web` component owns schema setup (`AUTO_MIGRATE`/`AUTO_SEED=true`); the
worker has both disabled so a parallel deploy cannot run migrations twice.
Seeders are idempotent, so re-running them on each deploy is safe.

Storage is ephemeral here too — use S3 for evidence files you need to keep.

### Railway

Railway reads [`railway.json`](../railway.json) and builds the app from
the Dockerfile.

1. *New Project → Deploy from GitHub repo* → select `monovm/abuseai`.
2. Add **PostgreSQL** and **Redis** via *New → Database*.
3. On the app service, set variables:
   - `APP_KEY` — generate as above
   - `APP_URL` — your `*.up.railway.app` URL
   - `DB_URL=${{Postgres.DATABASE_URL}}`
   - `REDIS_URL=${{Redis.REDIS_URL}}`
   - `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`,
     `SESSION_DRIVER=database`, `TRUSTED_PROXIES=*`
4. Add a second service from the same repo for the queue worker — set its
   start command to `php artisan horizon` and `CONTAINER_ROLE=horizon`.

### Coolify (self-hosted)

[Coolify](https://coolify.io) deploys the bundled `docker-compose.yml`
directly:

1. *New Resource → Docker Compose*.
2. Pick the Git source and point it at this repo.
3. Coolify reads `docker-compose.yml` — the full stack (app, Horizon,
   scheduler, PostgreSQL, Redis).
4. Set the environment variables (see `.env.docker.example`) and deploy.

Any other Docker host (Dokku, CapRover, a plain VM) can run the same
`docker-compose.yml`.

## Publishing the image (maintainers)

The `.github/workflows/docker-publish.yml` workflow builds and pushes a
multi-arch image on every version tag. It needs two repository secrets:

- `DOCKERHUB_USERNAME` — Docker Hub account name. For an **organization**
  access token this must be the organization name itself, not a member’s handle
- `DOCKERHUB_TOKEN` — Docker Hub access token with write scope
  (read/write/delete scope if the description sync below should work too)

GHCR uses the built-in `GITHUB_TOKEN`. Cut a release with:

```bash
git tag v1.0.0
git push origin v1.0.0
```

This publishes `monovmcom/abuseai.io:1.0.0`, `:1.0`, `:1`, and `:latest` to both
registries. Pushes to `main` publish the `edge` tag.

### Docker Hub repository description

The overview shown on the
[Docker Hub page](https://hub.docker.com/r/monovmcom/abuseai.io) is checked in
as [`docs/dockerhub-description.md`](dockerhub-description.md) and synced by
the same workflow after every successful publish — edit the file, merge to
`main`, and the page updates on the next run. Keep links in it **absolute**:
Docker Hub renders the Markdown outside the repository, so relative links
break there.
