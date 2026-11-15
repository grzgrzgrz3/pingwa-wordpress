#!/usr/bin/env bash
# Regenerate assets/join-wp-qr.svg, the QR of Pingwa_Settings::JOIN_URL, with segno,
# the generator the pingwa server uses for its own join QR codes. Run after changing
# JOIN_URL; tests/e2e/check_bodies.py fails while the two disagree.
set -euo pipefail
cd "$(dirname "$0")/.."
url=$(sed -n "s/^\tconst JOIN_URL = '\(.*\)';$/\1/p" includes/class-pingwa-settings.php)
[ -n "$url" ] || { echo "JOIN_URL not found in includes/class-pingwa-settings.php" >&2; exit 1; }
uvx --from 'segno==1.6.6' segno --error M --scale 6 --border 2 --dark '#000' --light '#fff' \
  --no-xmldecl --title 'join wp' --desc "$url" --output assets/join-wp-qr.svg "$url"
echo "assets/join-wp-qr.svg <- $url"
