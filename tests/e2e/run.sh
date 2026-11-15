#!/usr/bin/env bash
# E2E: WordPress + WooCommerce + Contact Form 7 in Docker, a local HTTPS stand-in for
# the pingwa server, the real admin page, a real form submit and a real block checkout;
# every body checked with the pingwa server's own code; then Plugin Check.
#
#   tests/e2e/run.sh            # everything, then tear down
#   KEEP=1 tests/e2e/run.sh     # leave the stack up (docker compose -p pingwa-wp down -v later)
#
# Needs: docker (compose v2), python3, openssl, uv, and the pingwa server repo
# (PINGWA_SERVER_REPO, read-only) for check_bodies.py. Every Docker resource is named
# pingwa-wp-*; nothing else is touched.
set -euo pipefail
cd "$(dirname "$0")"
E2E=$PWD
ROOT=$(cd ../.. && pwd)
SERVER=${PINGWA_SERVER_REPO:-$HOME/git/pingwa/server}
export PINGWA_WP_PORT=${PINGWA_WP_PORT:-18471}
BASE=http://127.0.0.1:$PINGWA_WP_PORT
export PINGWA_WP_BASE=$BASE

step() { printf '\n== %s\n' "$*"; }
wp() { docker exec -i pingwa-wp-cli wp "$@"; }
server_py() {
  ( cd "$SERVER" && PYTHONDONTWRITEBYTECODE=1 VIRTUAL_ENV= uv run --frozen --no-sync python "$@" )
}
teardown() {
  step "teardown"
  docker compose down -v --remove-orphans 2>&1 | tail -n 3 || true
}
if [ "${KEEP:-0}" != 1 ]; then trap teardown EXIT; fi

step "build the plugin"
"$ROOT/bin/build.sh" >/dev/null
ls "$ROOT/build/pingwa"

step "certificates for the stand-in server (hooks.pingwa-e2e.test)"
docker compose down -v --remove-orphans >/dev/null 2>&1 || true
rm -rf .run && mkdir -p .run/certs .run/captures
(
  cd .run/certs
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=pingwa-wp e2e CA" \
    -keyout ca.key -out ca.pem -addext basicConstraints=critical,CA:TRUE \
    -addext keyUsage=critical,keyCertSign 2>/dev/null
  openssl req -newkey rsa:2048 -nodes -subj "/CN=hooks.pingwa-e2e.test" \
    -keyout server.key -out server.csr 2>/dev/null
  printf 'subjectAltName=DNS:hooks.pingwa-e2e.test\nextendedKeyUsage=serverAuth\n' > ext.cnf
  openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 2 \
    -extfile ext.cnf -out server.pem 2>/dev/null
  chmod 644 ./*.key
)
chmod 777 .run/captures

step "start the stack"
RECEIVER_DELAY_S=0 docker compose up -d 2>&1 | tail -n 4
for _ in $(seq 1 90); do docker exec pingwa-wp-cli test -f /var/www/html/wp-config.php 2>/dev/null && break; sleep 1; done

step "install WordPress, WooCommerce, Contact Form 7, Plugin Check"
wp core install --url="$BASE" --title='Pingwa E2E Shop' --admin_user=admin \
  --admin_password=admin --admin_email=admin@example.com --skip-email
wp plugin install woocommerce contact-form-7 plugin-check --activate 2>&1 | grep -E '^(Success|Error|Warning)' || true
wp plugin activate pingwa
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_bacs_settings '{"enabled":"yes","title":"Direct bank transfer"}' --format=json >/dev/null
wp core version --extra | head -n 1
wp plugin list --fields=name,status,version
wp wc hpos enable 2>&1 | tail -n 1
echo "HPOS (order tables) enabled: $(wp option get woocommerce_custom_orders_table_enabled)"
[ "$(wp option get woocommerce_custom_orders_table_enabled)" = yes ]
PRODUCT=$(wp wc product create --name='E2E Mug' --regular_price=12.50 --virtual=true --user=admin --porcelain)
FORM=$(wp eval '
$f = WPCF7_ContactForm::get_template();
$f->set_title( "E2E contact" );
$f->set_properties( array( "form" => "[text* your-name] [email* your-email] [text your-subject] [checkbox topics \"Sales\" \"Support\"] [textarea your-message] [hidden ref \"default-ref\"] [file your-file] [submit \"Send\"]" ) );
$f->save();
echo $f->id();')
echo "product $PRODUCT, CF7 form $FORM"

step "hook URLs minted by the server's own code"
server_py "$E2E/check_bodies.py" "$ROOT" --mint 25

step "scenario (admin page, CF7 submit, block checkout, orders in code)"
python3 "$E2E/drive.py" "$PRODUCT" "$FORM"

step "bodies checked with the pingwa server's code ($SERVER, read-only)"
server_py "$E2E/check_bodies.py" "$ROOT" | tee .run/check-bodies.txt

step "PHP errors logged by WordPress during the run"
if docker logs pingwa-wp-wordpress 2>&1 | grep -iE 'PHP (Fatal|Warning|Notice|Deprecated).*pingwa'; then
  echo "pingwa raised PHP errors"; exit 1
fi
echo "none from pingwa"

step "Plugin Check"
set +e
PCP=(plugin check pingwa --require=./wp-content/plugins/plugin-check/cli.php
     --include-experimental --include-low-severity-errors --include-low-severity-warnings)
docker exec -w /var/www/html pingwa-wp-cli wp "${PCP[@]}" --format=table 2>&1 | tee .run/plugin-check.txt
docker exec -w /var/www/html pingwa-wp-cli wp "${PCP[@]}" --format=json 2>/dev/null > .run/plugin-check.json
set -e
ERRORS=$(python3 -c '
import json, sys
n = 0
for line in open(sys.argv[1]):
    line = line.strip()
    if line.startswith("["):
        n += sum(1 for r in json.loads(line) if r.get("type") == "ERROR")
print(n)' .run/plugin-check.json)
echo "Plugin Check errors: $ERRORS"
[ "$ERRORS" = 0 ]

step "E2E passed"
