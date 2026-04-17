#!/bin/sh
# Rode a partir da raiz da instalação Getfy (ex.: /opt/getfy):  sh docker/check-php-upload-limits.sh
# Ou: cd /opt/getfy && sh docker/check-php-upload-limits.sh
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Arquivo não encontrado: $ROOT_DIR/$ENV_FILE" >&2
  echo "Use o diretório onde o Getfy está instalado (ex.: cd /opt/getfy)." >&2
  exit 1
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

echo "Diretório: $ROOT_DIR"
echo "--- php.ini carregado ---"
docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" exec app php --ini
echo ""
echo "--- limites de upload ---"
docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" exec app php -r "echo 'upload_max_filesize=' . ini_get('upload_max_filesize') . PHP_EOL . 'post_max_size=' . ini_get('post_max_size') . PHP_EOL;"
