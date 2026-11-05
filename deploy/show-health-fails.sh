#!/usr/bin/env bash
# Show which Ownswitch health checks failed (latest report).
#   sudo bash /opt/ownswitch-src/deploy/show-health-fails.sh
set -euo pipefail
R="${1:-/var/lib/ownswitch-install/reports/latest.txt}"
[[ -f "$R" ]] || { echo "No report at $R — run: sudo bash /opt/ownswitch-src/deploy/install.sh --only 99"; exit 1; }
echo "Report: $R"
echo
if grep -E '^\s*\[?FAIL|FAIL  |FAIL\|' "$R" 2>/dev/null; then
  true
else
  # Fallback: summary lines that look like failures
  grep -i fail "$R" | head -n 40 || echo "(no FAIL lines found — checks may all be OK)"
fi
echo
echo "Re-run health: sudo bash /opt/ownswitch-src/deploy/install.sh --only 99"
