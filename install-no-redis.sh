#!/usr/bin/env bash
set -euo pipefail

REPO_URL="${GETFY_REPO_URL:-https://github.com/getfy-opensource/getfy.git}"
BRANCH="${GETFY_BRANCH:-main}"
INSTALL_DIR="${GETFY_DIR:-/opt/getfy}"
HTTP_PORT="${GETFY_HTTP_PORT:-80}"
SWAP_MODE="${GETFY_SWAP_MODE:-auto}"

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

if [ "$SWAP_MODE" != "off" ]; then
  MEM_KB="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
  SWAP_KB="$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
  MEM_GB=$(( (MEM_KB + 1048575) / 1048576 ))

  SHOULD_CREATE_SWAP=0
  if [ "$SWAP_MODE" = "on" ]; then
    SHOULD_CREATE_SWAP=1
  elif [ "$SWAP_MODE" = "auto" ]; then
    if [ "$SWAP_KB" -eq 0 ] && [ "$MEM_GB" -gt 0 ] && [ "$MEM_GB" -le 8 ]; then
      SHOULD_CREATE_SWAP=1
    fi
  fi

  if [ "$SHOULD_CREATE_SWAP" -eq 1 ]; then
    if ! swapon --show 2>/dev/null | awk 'NR>1 {print $1}' | grep -q '^/swapfile$'; then
      if [ ! -f /swapfile ]; then
        SWAP_GB=4
        if [ "$MEM_GB" -le 2 ]; then
          SWAP_GB=2
        fi

        if command -v fallocate >/dev/null 2>&1; then
          $SUDO fallocate -l "${SWAP_GB}G" /swapfile
        else
          $SUDO dd if=/dev/zero of=/swapfile bs=1M count=$((SWAP_GB * 1024)) status=progress
        fi
        $SUDO chmod 600 /swapfile
        $SUDO mkswap /swapfile >/dev/null
      fi

      $SUDO swapon /swapfile || true
      if ! grep -Eq '^\s*/swapfile\s+' /etc/fstab; then
        echo "/swapfile none swap sw 0 0" | $SUDO tee -a /etc/fstab >/dev/null
      fi
    fi
  fi
fi

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
  GIT_BASE=(git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR")
  $SUDO "${GIT_BASE[@]}" remote set-url origin "$REPO_URL" >/dev/null 2>&1 || true
  HAS_LOCAL_CHANGES=0
  STATUS_OUT="$($SUDO "${GIT_BASE[@]}" status --porcelain 2>/dev/null || true)"
  if [ -n "$STATUS_OUT" ]; then
    HAS_LOCAL_CHANGES=1
    if ! $SUDO "${GIT_BASE[@]}" stash push -u -m "getfy-install" >/dev/null 2>&1; then
      echo "Falha ao aplicar stash automaticamente. Resolva manualmente em: $INSTALL_DIR" >&2
      exit 1
    fi
  fi
  $SUDO "${GIT_BASE[@]}" fetch --all --prune
  if ! $SUDO "${GIT_BASE[@]}" checkout -B "$BRANCH" "origin/$BRANCH"; then
    echo "Falha ao atualizar código (checkout). Se você tem alterações locais, rode:" >&2
    echo "  cd \"$INSTALL_DIR\" && git stash push -u -m getfy-install" >&2
    exit 1
  fi
  $SUDO "${GIT_BASE[@]}" reset --hard "origin/$BRANCH"
  if [ "$HAS_LOCAL_CHANGES" -eq 1 ]; then
    if ! $SUDO "${GIT_BASE[@]}" stash pop >/dev/null 2>&1; then
      echo "Aviso: havia alterações locais. O instalador fez stash, mas não conseguiu reaplicar automaticamente." >&2
      echo "Para ver e resolver manualmente: cd \"$INSTALL_DIR\" && git stash list && git stash show -p" >&2
    fi
  fi
else
  $SUDO mkdir -p "$(dirname "$INSTALL_DIR")"
  $SUDO git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
fi

cd "$INSTALL_DIR"

$SUDO chmod +x docker/up.sh >/dev/null 2>&1 || true

export GETFY_QUEUE_CONNECTION="${GETFY_QUEUE_CONNECTION:-database}"
export GETFY_CACHE_STORE="${GETFY_CACHE_STORE:-file}"
export GETFY_SESSION_DRIVER="${GETFY_SESSION_DRIVER:-file}"
export GETFY_COMPOSE_FILES="${GETFY_COMPOSE_FILES:-docker-compose.no-redis.yml}"

if ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "(^|:)$HTTP_PORT$"; then
  echo "Aviso: porta $HTTP_PORT parece estar em uso. Se o compose falhar, mude GETFY_HTTP_PORT." >&2
fi

$SUDO sh docker/up.sh

IP="$(curl -fsSL https://api.ipify.org 2>/dev/null || true)"
if [ -z "$IP" ]; then
  IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
fi
if [ -z "$IP" ]; then
  IP="SEU_IP"
fi

echo ""
echo "Getfy iniciado via Docker (sem Redis; fila database/cache file)."
echo "Abra: http://$IP:$HTTP_PORT/docker-setup"
echo ""
echo "Se você adicionou seu usuário ao grupo docker, reabra o SSH para aplicar."
