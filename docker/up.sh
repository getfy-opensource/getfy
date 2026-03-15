#!/bin/sh
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

mkdir -p .docker

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  HTTP_PORT="${GETFY_HTTP_PORT:-80}"
  APP_URL="${GETFY_APP_URL:-http://localhost}"

  U="getfy_$(tr -dc 'a-z0-9' < /dev/urandom | head -c 8)"
  P="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
  R="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"

  cat > "$ENV_FILE" <<EOF
GETFY_DB_DATABASE=getfy
GETFY_DB_USERNAME=$U
GETFY_DB_PASSWORD=$P
GETFY_APP_URL=$APP_URL
GETFY_HTTP_PORT=$HTTP_PORT
GETFY_MYSQL_DATABASE=getfy
GETFY_MYSQL_USER=$U
GETFY_MYSQL_PASSWORD=$P
GETFY_MYSQL_ROOT_PASSWORD=$R
GETFY_QUEUE_CONNECTION=${GETFY_QUEUE_CONNECTION:-redis}
GETFY_CACHE_STORE=${GETFY_CACHE_STORE:-redis}
GETFY_SESSION_DRIVER=${GETFY_SESSION_DRIVER:-file}
GETFY_MYSQL_INNODB_BUFFER_POOL_SIZE=${GETFY_MYSQL_INNODB_BUFFER_POOL_SIZE:-256M}
GETFY_MYSQL_INNODB_LOG_FILE_SIZE=${GETFY_MYSQL_INNODB_LOG_FILE_SIZE:-64M}
GETFY_MYSQL_INNODB_BUFFER_POOL_INSTANCES=${GETFY_MYSQL_INNODB_BUFFER_POOL_INSTANCES:-1}
GETFY_MYSQL_MAX_CONNECTIONS=${GETFY_MYSQL_MAX_CONNECTIONS:-50}
GETFY_MYSQL_TABLE_OPEN_CACHE=${GETFY_MYSQL_TABLE_OPEN_CACHE:-200}
GETFY_MYSQL_THREAD_CACHE_SIZE=${GETFY_MYSQL_THREAD_CACHE_SIZE:-16}
GETFY_MYSQL_SKIP_TZINFO=${GETFY_MYSQL_SKIP_TZINFO:-1}
GETFY_REDIS_MAXMEMORY=${GETFY_REDIS_MAXMEMORY:-128mb}
GETFY_REDIS_MAXMEMORY_POLICY=${GETFY_REDIS_MAXMEMORY_POLICY:-allkeys-lru}
GETFY_QUEUE_WORKER_MEMORY=${GETFY_QUEUE_WORKER_MEMORY:-128}
GETFY_QUEUE_WORKER_MAX_TIME=${GETFY_QUEUE_WORKER_MAX_TIME:-3600}
GETFY_QUEUE_WORKER_MAX_JOBS=${GETFY_QUEUE_WORKER_MAX_JOBS:-1000}
GETFY_CADDY_HOST=${GETFY_CADDY_HOST:-:80}
EOF
else
  if grep -Eq '^\s*GETFY_DB_USERNAME\s*=\s*$' "$ENV_FILE" || grep -Eq '^\s*GETFY_DB_PASSWORD\s*=\s*$' "$ENV_FILE" \
    || grep -Eq '^\s*GETFY_DB_USERNAME\s*=\s*getfy\s*$' "$ENV_FILE" || grep -Eq '^\s*GETFY_DB_PASSWORD\s*=\s*getfy\s*$' "$ENV_FILE"; then
    U="getfy_$(tr -dc 'a-z0-9' < /dev/urandom | head -c 8)"
    P="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
    R="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
    TMP="$(mktemp)"
    awk -v U="$U" -v P="$P" -v R="$R" '
      BEGIN { u=0; p=0; r=0; mu=0; mp=0; mr=0 }
      $0 ~ /^GETFY_DB_USERNAME=/ { print "GETFY_DB_USERNAME=" U; u=1; next }
      $0 ~ /^GETFY_DB_PASSWORD=/ { print "GETFY_DB_PASSWORD=" P; p=1; next }
      $0 ~ /^GETFY_MYSQL_USER=/ { print "GETFY_MYSQL_USER=" U; mu=1; next }
      $0 ~ /^GETFY_MYSQL_PASSWORD=/ { print "GETFY_MYSQL_PASSWORD=" P; mp=1; next }
      $0 ~ /^GETFY_MYSQL_ROOT_PASSWORD=/ { print "GETFY_MYSQL_ROOT_PASSWORD=" R; mr=1; next }
      { print }
      END {
        if (!u) print "GETFY_DB_USERNAME=" U
        if (!p) print "GETFY_DB_PASSWORD=" P
        if (!mu) print "GETFY_MYSQL_USER=" U
        if (!mp) print "GETFY_MYSQL_PASSWORD=" P
        if (!mr) print "GETFY_MYSQL_ROOT_PASSWORD=" R
      }
    ' "$ENV_FILE" > "$TMP"
    mv "$TMP" "$ENV_FILE"
  fi
fi

COMPOSE_FILES="${GETFY_COMPOSE_FILES:-docker-compose.yml}"
COMPOSE_ARGS=""
OLD_IFS="$IFS"
IFS=';'
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  fi
done
IFS="$OLD_IFS"

docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" up --build -d
