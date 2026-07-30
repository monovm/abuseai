#!/bin/sh
# =====================================================================
# Abuse AI container entrypoint.
#
# Behaviour is driven by CONTAINER_ROLE:
#   app        — web server: runs migrations, links storage, caches.
#   horizon    — queue workers: waits for DB, then runs the given command.
#   scheduler  — cron loop: waits for DB, then runs the given command.
#
# Toggles:
#   AUTO_MIGRATE (default true)  — run `migrate --force` on the app role.
#   AUTO_SEED    (default false) — run `db:seed --force` on the app role.
# =====================================================================
set -e
cd /app

role="${CONTAINER_ROLE:-app}"

log() { echo "[entrypoint] $*"; }

# PaaS platforms (Render, Railway, Fly, Heroku-likes) inject the port the
# web server must bind via $PORT. Map it onto FrankenPHP's listen address.
if [ -n "${PORT}" ]; then
	export SERVER_NAME=":${PORT}"
fi

# --- PaaS-provided connection strings --------------------------------
# Attached add-ons name their own config vars: Heroku Postgres exports
# DATABASE_URL, and some Redis add-ons expose the TLS endpoint separately as
# REDIS_TLS_URL. config/database.php reads DB_URL and REDIS_URL, so alias
# them here rather than making every platform template restate the value.
if [ -z "${DB_URL}" ] && [ -n "${DATABASE_URL}" ]; then
	export DB_URL="${DATABASE_URL}"
fi
if [ -z "${REDIS_URL}" ] && [ -n "${REDIS_TLS_URL}" ]; then
	export REDIS_URL="${REDIS_TLS_URL}"
fi

# --- Ensure an application key exists --------------------------------
# If APP_KEY is not supplied via the environment, generate one once and
# persist it on the (volume-mounted) storage dir so it survives restarts.
if [ -z "${APP_KEY}" ]; then
	key_file="/app/storage/app/.appkey"
	if [ -f "${key_file}" ]; then
		APP_KEY="$(cat "${key_file}")"
	else
		APP_KEY="$(php artisan key:generate --show)"
		mkdir -p "$(dirname "${key_file}")"
		echo "${APP_KEY}" > "${key_file}"
		log "Generated a new APP_KEY (stored in storage/app/.appkey)."
		log "Set APP_KEY explicitly in your environment for a fixed key."
	fi
	export APP_KEY
fi

# --- Wait for the database to accept connections ---------------------
# Probe connectivity and nothing else. `artisan db:show` is deliberately not
# used: once the schema has tables it formats their sizes via Number::format(),
# so it can fail for reasons that have nothing to do with reachability and the
# loop then spins until it gives up.
db_probe='require "/app/vendor/autoload.php";
$app = require "/app/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::connection()->getPdo();'

wait_for_db() {
	tries=0
	until php -r "${db_probe}" >/dev/null 2>&1; do
		tries=$((tries + 1))
		if [ "${tries}" -ge 60 ]; then
			log "Database not reachable after 60 attempts — giving up. Last error:"
			php -r "${db_probe}" 2>&1 | tail -5
			exit 1
		fi
		log "Waiting for database... (${tries}/60)"
		sleep 2
	done
	log "Database is reachable."
}

wait_for_db

# --- App role: schema + one-time setup -------------------------------
if [ "${role}" = "app" ]; then
	if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
		log "Running database migrations..."
		php artisan migrate --force --no-interaction
	fi
	if [ "${AUTO_SEED:-false}" = "true" ]; then
		log "Seeding database..."
		php artisan db:seed --force --no-interaction
	fi
	php artisan storage:link >/dev/null 2>&1 || true
fi

# --- Cache config/routes/views for every role ------------------------
log "Optimizing application caches..."
php artisan config:cache
php artisan event:cache
php artisan view:cache
php artisan route:cache 2>/dev/null || log "route:cache skipped (closure routes present)."

log "Starting role '${role}': $*"
exec "$@"
