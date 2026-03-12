#!/usr/bin/env bash
set -euo pipefail

REPO_URL="${GETFY_REPO_URL:-https://github.com/getfy-opensource/getfy.git}"
BRANCH="${GETFY_BRANCH:-main}"
INSTALL_DIR="${GETFY_DIR:-/opt/getfy}"
HTTP_PORT="${GETFY_HTTP_PORT:-80}"

if [ "$(uname -s)" != "Linux" ]; then
  echo "Este instalador é para Linux." >&2
  exit 1
fi

if ! command -v bash >/dev/null 2>&1; then
  echo "bash não encontrado." >&2
  exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
  echo "Distribuição não suportada (precisa de apt-get, ex.: Ubuntu/Debian)." >&2
  exit 1
fi

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    echo "Rode como root ou instale sudo." >&2
    exit 1
  fi
fi

export DEBIAN_FRONTEND=noninteractive

$SUDO apt-get update -y
$SUDO apt-get install -y ca-certificates curl git gnupg lsb-release

if ! command -v docker >/dev/null 2>&1; then
  $SUDO install -m 0755 -d /etc/apt/keyrings
  $SUDO rm -f /etc/apt/keyrings/docker.gpg
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | $SUDO gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  $SUDO chmod a+r /etc/apt/keyrings/docker.gpg

  CODENAME="$(. /etc/os-release && echo "${VERSION_CODENAME:-}")"
  if [ -z "$CODENAME" ]; then
    CODENAME="$(lsb_release -cs 2>/dev/null || true)"
  fi
  if [ -z "$CODENAME" ]; then
    echo "Não foi possível detectar o codename do Ubuntu/Debian." >&2
    exit 1
  fi

  ARCH="$(dpkg --print-architecture)"
  echo "deb [arch=$ARCH signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $CODENAME stable" | $SUDO tee /etc/apt/sources.list.d/docker.list >/dev/null

  $SUDO apt-get update -y
  $SUDO apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  $SUDO systemctl enable --now docker >/dev/null 2>&1 || true
fi

if [ -n "${SUDO_USER:-}" ] && id -nG "$SUDO_USER" 2>/dev/null | grep -qw docker; then
  :
elif [ -n "${SUDO_USER:-}" ]; then
  $SUDO usermod -aG docker "$SUDO_USER" || true
fi

if [ -e "$INSTALL_DIR" ] && [ ! -d "$INSTALL_DIR" ]; then
  echo "Destino existe e não é diretório: $INSTALL_DIR" >&2
  exit 1
fi

if [ -d "$INSTALL_DIR/.git" ]; then
  $SUDO git -C "$INSTALL_DIR" fetch --all --prune
  $SUDO git -C "$INSTALL_DIR" checkout "$BRANCH"
  $SUDO git -C "$INSTALL_DIR" pull --ff-only origin "$BRANCH"
else
  $SUDO mkdir -p "$(dirname "$INSTALL_DIR")"
  $SUDO git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
fi

cd "$INSTALL_DIR"

if [ -f ".docker/stack.env" ]; then
  if grep -Eq '^\s*GETFY_HTTP_PORT\s*=' ".docker/stack.env"; then
    $SUDO awk -v port="$HTTP_PORT" '
      $0 ~ /^GETFY_HTTP_PORT=/ { print "GETFY_HTTP_PORT=" port; next }
      { print }
    ' ".docker/stack.env" > ".docker/stack.env.tmp" && $SUDO mv ".docker/stack.env.tmp" ".docker/stack.env"
  else
    echo "GETFY_HTTP_PORT=$HTTP_PORT" | $SUDO tee -a ".docker/stack.env" >/dev/null
  fi
fi

$SUDO chmod +x docker/up.sh >/dev/null 2>&1 || true

if ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "(^|:)$HTTP_PORT$"; then
  echo "Aviso: porta $HTTP_PORT parece estar em uso. Se o compose falhar, mude GETFY_HTTP_PORT." >&2
fi

if [ -f ".docker/stack.env" ]; then
  $SUDO sh docker/up.sh
else
  $SUDO sh docker/up.sh
fi

IP="$(curl -fsSL https://api.ipify.org 2>/dev/null || true)"
if [ -z "$IP" ]; then
  IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
fi
if [ -z "$IP" ]; then
  IP="SEU_IP"
fi

echo ""
echo "Getfy iniciado via Docker."
echo "Abra: http://$IP:$HTTP_PORT/docker-setup"
echo ""
echo "Se você adicionou seu usuário ao grupo docker, reabra o SSH para aplicar."

