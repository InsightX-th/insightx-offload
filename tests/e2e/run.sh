#!/usr/bin/env bash
#
# End-to-end test against a real S3-compatible server and a real MySQL.
#
# Starts SeaweedFS (S3 API with SigV4 verification) on 127.0.0.1, builds a
# throw-away WordPress in its own database, then drives the plugin through
# WP-CLI: bulk offload with permanent URL rewrite, Remove from Bucket with a
# size missing from the bucket, orphan cleanup safety, and Migrate to another
# bucket. Everything is removed at the end (KEEP=1 keeps it).
#
#   WEED_BIN=/path/to/weed bash tests/e2e/run.sh
#
# SeaweedFS: https://github.com/seaweedfs/seaweedfs/releases (single binary).
# MySQL/WP-CLI default to a running Local (localwp.com) site, like the
# InsightX Backup e2e; override with MYSQL_BIN, MYSQL_SOCKET or DB_HOST,
# DB_USER, DB_PASS, WP_CORE, WP_CLI, PHP_BIN.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HERE/../.." && pwd)"
LOCAL_RES="/Applications/Local.app/Contents/Resources/extraResources"

PHP_BIN="${PHP_BIN:-php}"
WEED_BIN="${WEED_BIN:-$(command -v weed || true)}"
WP_CLI="${WP_CLI:-$LOCAL_RES/bin/wp-cli/wp-cli.phar}"
MYSQL_BIN="${MYSQL_BIN:-$(ls "$LOCAL_RES"/lightning-services/mysql-*/bin/darwin-*/bin/mysql 2>/dev/null | head -1 || true)}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
DB_HOST="${DB_HOST:-}"
[ -n "$DB_HOST" ] || MYSQL_SOCKET="${MYSQL_SOCKET:-$(ls "$HOME"/Library/Application\ Support/Local/run/*/mysql/mysqld.sock 2>/dev/null | head -1 || true)}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
WP_CORE="${WP_CORE:-$(cd "$PLUGIN/../../.." && pwd)}"
S3_PORT="${S3_PORT:-18333}"

NEEDS=( "$WEED_BIN" "$WP_CLI" "$WP_CORE/wp-includes/version.php" )
[ -n "$DB_HOST" ] || NEEDS+=( "${MYSQL_SOCKET:-}" )
for need in "${NEEDS[@]}"; do
	[ -n "$need" ] && [ -e "$need" ] || { echo "Missing: '${need}' (WEED_BIN? is Local running?)"; exit 2; }
done

if [ -n "$DB_HOST" ]; then
	CONN=( --host="${DB_HOST%%:*}" --port="${DB_HOST##*:}" --protocol=tcp ); WP_DBHOST="$DB_HOST"
else
	CONN=( --socket="$MYSQL_SOCKET" ); WP_DBHOST="localhost:$MYSQL_SOCKET"
fi
# MYSQL_PWD rather than -p: no "password on the command line" warning, so
# stderr can stay visible and a failing statement says why.
SQL() { MYSQL_PWD="$DB_PASS" "$MYSQL_BIN" "${CONN[@]}" -u"$DB_USER" "$@"; }

WORK="$(mktemp -d /tmp/isxm-e2e.XXXXXX)"
SITE="$WORK/site"
DB=isxm_e2e
ENDPOINT="http://127.0.0.1:$S3_PORT"
WEED_PID=""

cleanup() {
	if [ -n "$WEED_PID" ]; then
		# weed can sit in a graceful shutdown for a long time; a survivor
		# would hold the ports and break the next run.
		kill "$WEED_PID" 2>/dev/null || true
		for _ in 1 2 3 4 5; do kill -0 "$WEED_PID" 2>/dev/null || break; sleep 1; done
		kill -9 "$WEED_PID" 2>/dev/null || true
	fi
	if [ "${KEEP:-0}" = "1" ]; then echo "KEEP=1 — left $WORK and database $DB"; return; fi
	SQL -e "DROP DATABASE IF EXISTS $DB;" || true
	rm -rf "$WORK"
}
trap cleanup EXIT

echo "== starting SeaweedFS S3 on $ENDPOINT"
mkdir -p "$WORK/weed"
cat > "$WORK/s3.json" <<'JSON'
{"identities":[{"name":"e2e","credentials":[{"accessKey":"e2eaccess","secretKey":"e2esecret"}],"actions":["Admin","Read","Write","List","Tagging"]}]}
JSON
"$WEED_BIN" server -ip=127.0.0.1 -dir="$WORK/weed" -master.port=$((S3_PORT + 1)) -volume.port=$((S3_PORT + 2)) \
	-filer -filer.port=$((S3_PORT + 3)) -s3 -s3.port="$S3_PORT" -s3.config="$WORK/s3.json" >"$WORK/weed.log" 2>&1 &
WEED_PID=$!
for _ in $(seq 1 60); do
	curl -s -o /dev/null "$ENDPOINT" && break
	sleep 1
done
curl -s -o /dev/null "$ENDPOINT" || { echo "SeaweedFS did not start:"; tail -20 "$WORK/weed.log"; exit 1; }

wp() { "$PHP_BIN" -d memory_limit=1G "$WP_CLI" --path="$SITE" --skip-themes "$@"; }
stage() { wp eval-file "$HERE/e2e.php" "$1" "$ENDPOINT" "$WORK"; }

echo "== building WordPress (database $DB)"
SQL -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4;"
mkdir -p "$SITE"
rsync -a --exclude wp-content --exclude wp-config.php "$WP_CORE"/ "$SITE"/
mkdir -p "$SITE/wp-content/plugins" "$SITE/wp-content/themes/isxm-e2e" "$SITE/wp-content/uploads"
printf '/*\nTheme Name: ISXM E2E\n*/\n' > "$SITE/wp-content/themes/isxm-e2e/style.css"
echo '<?php' > "$SITE/wp-content/themes/isxm-e2e/index.php"
rsync -a --exclude .git --exclude tests "$PLUGIN"/ "$SITE/wp-content/plugins/insightx-offload/"
wp config create --dbname="$DB" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$WP_DBHOST" --skip-check --quiet
wp core install --url=http://site.test --title=E2E --admin_user=admin --admin_password=x --admin_email=a@example.com --skip-email --quiet
wp theme activate isxm-e2e --quiet
wp plugin activate insightx-offload --quiet

echo "== configure + seed"
stage configure
stage seed

echo "== bulk offload (wp isxm offload)"
wp isxm offload
stage offloaded

echo "== remove from bucket with a size missing"
stage remove

echo "== orphan cleanup safety"
stage orphans

echo "== migrate to another bucket (dashboard job path: wp isxm job start/run)"
wp eval "ISXM_Settings::save( array_merge( ISXM_Settings::all(), array( 'provider' => 'minio', 'source_provider' => 'custom', 'source_prefix' => 'wp-content/uploads/', 'source_use_year_month' => true ) ) );"
# The job framework is what the dashboard button drives (run_migrate_batch);
# `wp isxm migrate` has its own simpler loop.
wp isxm job start migrate
wp isxm job run
stage migrated

echo "== E2E OK"
