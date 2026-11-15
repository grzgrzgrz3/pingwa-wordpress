#!/usr/bin/env bash
# Regenerate assets/join-wp-qr.svg, the QR of PINGWA_JOIN_URL (pingwa.php), with segno,
# the generator the pingwa server uses for its own join QR codes. Run after changing
# PINGWA_JOIN_URL; tests/e2e/check_bodies.py fails while the two disagree.
set -euo pipefail
cd "$(dirname "$0")/.."
url=$(sed -n "s/^define( 'PINGWA_JOIN_URL', '\(.*\)' );$/\1/p" pingwa.php)
[ -n "$url" ] || { echo "PINGWA_JOIN_URL not found in pingwa.php" >&2; exit 1; }
uvx --from 'segno==1.6.6' segno --error M --scale 6 --border 2 --dark '#000' --light '#fff' \
  --no-xmldecl --title 'join wp' --output assets/join-wp-qr.svg "$url"
echo "assets/join-wp-qr.svg <- $url"
