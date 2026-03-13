#!/bin/sh
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

mkdir -p .docker

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  U="getfy_$(tr -dc 'a-z0-9' < /dev/urandom | head -c 8)"
  P="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
  R="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"

  cat > "$ENV_FILE" <<EOF
GETFY_DB_DATABASE=getfy
GETFY_DB_USERNAME=$U
GETFY_DB_PASSWORD=$P
GETFY_APP_URL=http://localhost
GETFY_HTTP_PORT=80
GETFY_HTTPS_PORT=443
GETFY_MYSQL_DATABASE=getfy
GETFY_MYSQL_USER=$U
GETFY_MYSQL_PASSWORD=$P
GETFY_MYSQL_ROOT_PASSWORD=$R
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

docker compose --env-file "$ENV_FILE" up --build -d
