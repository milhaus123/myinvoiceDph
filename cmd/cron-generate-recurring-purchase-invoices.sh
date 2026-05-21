#!/usr/bin/env bash
# =============================================================================
#  cron-generate-recurring-purchase-invoices.sh — generování pravidelných nákupních faktur
#  Frekvence: 1× denně, doporučeno 06:35 (5 min po recurring vydaných fakturách)
#
#  Prochází šablony pravidelných nákupních faktur kde status='active'
#  a next_run_date <= dnes a vygeneruje nákupní fakturu. Podle per-šablona
#  flagu auto_issue rovnou přechod draft → received.
#
#  Volitelné argumenty:
#    --dry-run       jen vypíše, co by se vygenerovalo
#
#  crontab (každý den 06:35):
#    35 6 * * *  /var/www/myinvoice.cz/cmd/cron-generate-recurring-purchase-invoices.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="$PROJECT_ROOT/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-generate-recurring-purchase-invoices.php" "$@" \
    >> "$LOG_DIR/generate-recurring-purchase-$(date +%Y-%m-%d).log" 2>&1
