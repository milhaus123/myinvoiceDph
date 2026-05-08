#!/usr/bin/env bash
# MyInvoice.cz — Docker upgrade watcher.
#
# Sleduje storage/upgrade-requested.json a když ho UI vytvoří (POST
# /api/admin/update/trigger), spustí docker-update.sh a výsledek zapíše
# do storage/upgrade-result.json. UI to pak v Settings → Aktualizace
# zobrazí jako „aplikováno / selhalo".
#
# Provoz:
#   - Pust jako systemd unit, supervisord, nebo "while true; do" smyčku
#     v session přihlášené k host shellu.
#   - Volitelně 1x denně přes cron, ale to znamená, že upgrade odsune
#     až o 24h od kliknutí v UI.
#
# Příklad systemd unit (/etc/systemd/system/myinvoice-update-watcher.service):
#
#   [Unit]
#   Description=MyInvoice update watcher
#   After=docker.service
#
#   [Service]
#   Type=simple
#   WorkingDirectory=/opt/myinvoice
#   ExecStart=/opt/myinvoice/cmd/docker-update-watcher.sh
#   Restart=always
#
#   [Install]
#   WantedBy=multi-user.target
#
# Idempotent — flag se zpracovává jednou (move před spuštěním).

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

FLAG="storage/upgrade-requested.json"
RESULT="storage/upgrade-result.json"
INFLIGHT="storage/upgrade-inflight.json"
INTERVAL_S="${MYINVOICE_WATCHER_INTERVAL:-30}"

mkdir -p storage
echo "[watcher] start, polling ${FLAG} every ${INTERVAL_S}s (cwd: ${PROJECT_ROOT})"

write_result() {
    local status="$1"
    local target="$2"
    local message="$3"
    local applied_at
    applied_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    cat > "$RESULT" <<EOF
{
  "status": "${status}",
  "target_version": "${target}",
  "applied_at": "${applied_at}",
  "message": $(printf '%s' "$message" | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))' 2>/dev/null || printf '"%s"' "${message//\"/\\\"}")
}
EOF
}

while true; do
    if [[ -f "$FLAG" ]]; then
        # Přesunutí před spuštěním — zabrání double-trigger, kdyby smyčka
        # běžela dvakrát (např. dva watchery).
        mv "$FLAG" "$INFLIGHT"

        TARGET="$(grep -oE '"target_version"\s*:\s*"[^"]+"' "$INFLIGHT" | head -1 | sed -E 's/.*"target_version"\s*:\s*"([^"]+)".*/\1/')"
        TARGET="${TARGET:-latest}"

        echo "[watcher] $(date -u +%FT%TZ) upgrade requested → ${TARGET}"

        LOG="storage/upgrade-$(date -u +%Y%m%dT%H%M%SZ).log"
        if bash "$PROJECT_ROOT/cmd/docker-update.sh" >"$LOG" 2>&1; then
            echo "[watcher] OK"
            write_result "applied" "$TARGET" "Upgrade dokončen. Log: ${LOG}"
        else
            echo "[watcher] FAILED (rc=$?). Viz $LOG"
            write_result "failed" "$TARGET" "Upgrade selhal. Log: ${LOG}"
        fi
        rm -f "$INFLIGHT"
    fi
    sleep "$INTERVAL_S"
done
