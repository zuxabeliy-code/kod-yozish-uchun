#!/usr/bin/env bash
# Jarvis o'rnatish: Ubuntu 22.04/24.04. Ishlatish:  bash install.sh
set -euo pipefail
cd "$(dirname "$0")"

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo"; fi

echo "==> Tizim paketlari (python3-venv, ffmpeg)"
$SUDO apt-get update -y
$SUDO apt-get install -y python3 python3-venv python3-pip ffmpeg

PYV=$(python3 -c 'import sys; print("%d%02d" % sys.version_info[:2])')
if [ "$PYV" -lt 311 ]; then
  echo "XATO: Python 3.11+ kerak (hozir: $(python3 --version)). Ubuntu 22.04 da: sudo apt install python3.11 python3.11-venv va PYTHON=python3.11 bash install.sh" >&2
  [ -n "${PYTHON:-}" ] || exit 1
fi

echo "==> Virtual muhit va kutubxonalar"
"${PYTHON:-python3}" -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt

mkdir -p data
chmod 700 data

if [ ! -f .env ]; then
  cp .env.example .env
  chmod 600 .env
  echo "==> .env yaratildi. Uni to'ldiring:  nano .env"
else
  echo "==> .env allaqachon bor, tegilmadi"
fi

cat <<MSG

Tayyor. Keyingi qadamlar:
  1) nano .env           (API_ID, API_HASH, OWNER_ID, ANTHROPIC_API_KEY)
  2) .venv/bin/python login.py     (telefon raqam / kod / 2FA)
  3) systemd:  README.md dagi 4-qadam
MSG
