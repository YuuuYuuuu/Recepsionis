#!/usr/bin/env bash
# Setup GitHub Actions self-hosted runner (sekali di VPS).
# Runner connect KELUAR ke GitHub — tidak perlu buka SSH untuk IP GitHub.
#
# Prasyarat: SSH ke VPS sebagai user yang punya akses git di folder project.
#
# Langkah:
# 1. GitHub repo → Settings → Actions → Runners → New self-hosted runner → Linux
# 2. Copy token dari halaman tersebut (expires ~1 jam)
# 3. Jalankan di VPS:
#      export RUNNER_TOKEN="ghp_xxx..."
#      bash /www/wwwroot/Recepsionis/scripts/install-github-runner.sh

set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/YuuuYuuuu/Recepsionis}"
RUNNER_VERSION="${RUNNER_VERSION:-2.321.0}"
ROOT="${RECEPSIONIS_ROOT:-/www/wwwroot/Recepsionis}"
RUNNER_DIR="${RUNNER_DIR:-$ROOT/actions-runner}"

if [[ -z "${RUNNER_TOKEN:-}" ]]; then
  echo "ERROR: Set RUNNER_TOKEN dari GitHub (Settings → Actions → Runners → New runner)."
  echo "  export RUNNER_TOKEN=\"...\""
  exit 1
fi

mkdir -p "$RUNNER_DIR"
cd "$RUNNER_DIR"

ARCH="linux-x64"
TARBALL="actions-runner-${ARCH}-${RUNNER_VERSION}.tar.gz"
if [[ ! -f "$TARBALL" ]]; then
  curl -fsSL -o "$TARBALL" \
    "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${TARBALL}"
  tar xzf "$TARBALL"
fi

if [[ ! -f .runner ]]; then
  ./config.sh \
    --url "$REPO_URL" \
    --token "$RUNNER_TOKEN" \
    --name "vps-recepsionis" \
    --labels "self-hosted,Linux,X64,recepsionis" \
    --work "_work" \
    --unattended \
    --replace
fi

if command -v sudo >/dev/null 2>&1; then
  sudo ./svc.sh install || ./svc.sh install
  sudo ./svc.sh start || ./svc.sh start
else
  echo "Install service manual: ./run.sh"
fi

echo ""
echo "Runner terpasang di: $RUNNER_DIR"
echo "Cek di GitHub → Settings → Actions → Runners (harus Online)."
