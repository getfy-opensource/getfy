#!/usr/bin/env bash
set -euo pipefail

REPO_URL="${GETFY_REPO_URL:-https://github.com/getfy-opensource/getfy.git}"
BRANCH="${GETFY_BRANCH:-main}"
INSTALL_DIR="${GETFY_DIR:-/opt/getfy}"

if [ "$(uname -s)" != "Linux" ]; then
  echo "Este script é para Linux." >&2
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

if ! command -v git >/dev/null 2>&1; then
  echo "git não encontrado." >&2
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker não encontrado." >&2
  exit 1
fi

if [ ! -d "$INSTALL_DIR" ]; then
  echo "Diretório não encontrado: $INSTALL_DIR" >&2
  exit 1
fi

if [ ! -d "$INSTALL_DIR/.git" ]; then
  echo "Atualização manual indisponível: diretório não é um repositório Git (.git ausente)." >&2
  exit 1
fi

GIT_BASE=(git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR")
$SUDO "${GIT_BASE[@]}" remote set-url origin "$REPO_URL" >/dev/null 2>&1 || true

HAS_LOCAL_CHANGES=0
if [ -n "$($SUDO "${GIT_BASE[@]}" status --porcelain 2>/dev/null || true)" ]; then
  HAS_LOCAL_CHANGES=1
  $SUDO "${GIT_BASE[@]}" stash push -u -m "getfy-update" >/dev/null 2>&1 || true
fi

$SUDO "${GIT_BASE[@]}" fetch --all --prune
$SUDO "${GIT_BASE[@]}" checkout -B "$BRANCH" "origin/$BRANCH"
$SUDO "${GIT_BASE[@]}" reset --hard "origin/$BRANCH"

if [ "$HAS_LOCAL_CHANGES" -eq 1 ]; then
  if ! $SUDO "${GIT_BASE[@]}" stash pop >/dev/null 2>&1; then
    echo "Aviso: havia alterações locais. O script fez stash, mas não conseguiu reaplicar automaticamente." >&2
    echo "Para ver e resolver manualmente: cd \"$INSTALL_DIR\" && git stash list && git stash show -p" >&2
  fi
fi

cd "$INSTALL_DIR"
$SUDO sh docker/up.sh

echo ""
echo "Atualização concluída e stack reiniciado."
echo "Dica: para conferir limites de upload do PHP no container, na pasta da instalação (ex.: cd \"$INSTALL_DIR\"): sh docker/check-php-upload-limits.sh"
