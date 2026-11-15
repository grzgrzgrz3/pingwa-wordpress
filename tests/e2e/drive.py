"""E2E scenario against the Docker WordPress (run by run.sh, host Python, stdlib only).

Drives the real admin page, a real Contact Form 7 submit and a real block (Store API)
checkout, plus orders made in code; every POST the plugin makes lands in
.run/captures/ (receiver.py). Asserts what must and must not arrive; check_bodies.py
then renders each body with the pingwa server's own code.
"""

import http.cookiejar
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
CAPTURES = HERE / ".run" / "captures"
BASE = os.environ.get("PINGWA_WP_BASE", "http://127.0.0.1:18471")
TOKEN = "hk_PingwaWordPressE2eToken012345678"
HOOK_URL = f"https://hooks.pingwa-e2e.test/h/{TOKEN}"
RESULTS: list[str] = []


def ok(cond, what):
    RESULTS.append(("PASS " if cond else "FAIL ") + what)
    print(RESULTS[-1], flush=True)
    if not cond:
        sys.exit(1)


def wp(*args, stdin=None) -> str:
    out = subprocess.run(["docker", "exec", "-i", "pingwa-wp-cli", "wp", *args], input=stdin,
                         capture_output=True, text=True, check=True)
    return out.stdout.strip()


def wp_eval(php: str) -> str:
    return wp("eval", php)


class Browser:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, method, path, data=None, headers=None):
        req = urllib.request.Request(BASE + path, data=data, method=method, headers=headers or {})
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, dict(r.headers), r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode()

    def form(self, path, fields):
        return self.request("POST", path, urllib.parse.urlencode(fields, doseq=True).encode(),
                            {"Content-Type": "application/x-www-form-urlencoded"})

    def json(self, path, obj, headers):
        return self.request("POST", path, json.dumps(obj).encode(),
                            {"Content-Type": "application/json", **headers})


def captures() -> list[dict]:
    return [json.loads(p.read_text()) for p in sorted(CAPTURES.glob("[0-9]*.json"))]


def wait_captures(n, timeout=15.0) -> list[dict]:
    end = time.time() + timeout
    while time.time() < end:
        got = captures()
        if len(got) >= n:
            return got
        time.sleep(0.2)
    return captures()


def settle(seconds=4.0):
    """Wait long enough that a POST that should not happen would have arrived."""
    time.sleep(seconds)


def body(cap) -> dict:
    return json.loads(cap["body"])


def nonce_for(page, action):
    m = re.search(rf'name="action" value="{action}">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([0-9a-f]+)"', page)
    return m.group(1) if m else None


def notice(page):
    m = re.search(r'<div class="notice notice-(\w+) is-dismissible"><p>(.*?)</p></div>', page, re.S)
    return (m.group(1), m.group(2)) if m else (None, None)


def admin_page(b):
    return b.request("GET", "/wp-admin/options-general.php?page=pingwa")[2]


def save(b, url, triggers):
    page = admin_page(b)
    fields = {"action": "pingwa_save", "_wpnonce": nonce_for(page, "pingwa_save"),
              "_wp_http_referer": "/wp-admin/options-general.php?page=pingwa", "pingwa_hook_url": url}
    for t in triggers:
        fields[f"pingwa_triggers[{t}]"] = "1"
    return b.form("/wp-admin/admin-post.php", fields)[2]  # the redirect back shows the notice


def multipart(fields):
    boundary = uuid.uuid4().hex
    parts = []
    for name, value in fields:
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n')
    parts.append(f"--{boundary}--\r\n")
    return "".join(parts).encode(), f"multipart/form-data; boundary={boundary}"


def cf7_submit(b, form_id):
    data, ctype = multipart([
        ("_wpcf7", form_id), ("_wpcf7_version", "6.2.5"), ("_wpcf7_locale", "en_US"),
        ("_wpcf7_unit_tag", f"wpcf7-f{form_id}-o1"), ("_wpcf7_container_post", "0"),
        ("your-name", "Ada Lovelace"), ("your-email", "ada@example.com"),
        ("your-subject", "Quote for 40 mugs"), ("topics[]", "Sales"), ("topics[]", "Support"),
        ("your-message", "Hello,\nplease call me back."), ("ref", "secret-ref-value"),
    ])
    t0 = time.time()
    status, _, text = b.request("POST", f"/?rest_route=/contact-form-7/v1/contact-forms/{form_id}/feedback",
                                data, {"Content-Type": ctype})
    return status, json.loads(text), time.time() - t0


BUYER = {"first_name": "Ada", "last_name": "Lovelace", "company": "", "address_1": "969 Market",
         "address_2": "", "city": "San Francisco", "state": "CA", "postcode": "94103",
         "country": "US", "email": "buyer-secret@example.com", "phone": "+15550001111"}


def store_checkout(product_id):
    shop = Browser()
    _, headers, _ = shop.request("GET", "/?rest_route=/wc/store/v1/cart")
    nonce = {"Nonce": headers.get("Nonce") or headers.get("nonce", "")}
    status, _, text = shop.json("/?rest_route=/wc/store/v1/cart/add-item", {"id": product_id, "quantity": 2}, nonce)
    assert status in (200, 201), (status, text[:300])
    shipping = {k: v for k, v in BUYER.items() if k != "email"}
    t0 = time.time()
    status, _, text = shop.json("/?rest_route=/wc/store/v1/checkout",
                                {"billing_address": BUYER, "shipping_address": shipping,
                                 "payment_method": "bacs"}, nonce)
    return status, json.loads(text), time.time() - t0


def code_order(product_id) -> int:
    return int(wp_eval(
        f"$o = wc_create_order(); $o->add_product( wc_get_product( {product_id} ), 1 );"
        "$o->set_billing_first_name( 'Grace' ); $o->set_billing_last_name( 'Hopper' );"
        "$o->set_billing_email( 'grace-secret@example.com' ); $o->set_billing_phone( '+15552223333' );"
        "$o->set_billing_address_1( '1 Navy Way' ); $o->set_payment_method_title( 'Cash on delivery' );"
        "$o->calculate_totals(); $o->set_status( 'processing' ); $o->save(); echo $o->get_id();"))


def main():
    product_id = int(sys.argv[1])
    form_id = sys.argv[2]

    admin = Browser()
    admin.request("GET", "/wp-login.php")
    admin.form("/wp-login.php", {"log": "admin", "pwd": "admin", "wp-submit": "Log In", "testcookie": "1",
                                 "redirect_to": "/wp-admin/"})
    page = admin_page(admin)
    ok("join wp" in page and "https://wa.me/48797882513?text=join%20wp" in page,
       "settings page shows the join wp link")
    ok("assets/join-wp-qr.svg" in page, "settings page shows the shipped QR image")
    status, _, svg = admin.request("GET", "/wp-content/plugins/pingwa/assets/join-wp-qr.svg")
    ok(status == 200 and svg.startswith("<svg"), "QR SVG is served from the plugin")

    # Ruling 2: nonce and capability.
    status, _, _ = admin.form("/wp-admin/admin-post.php", {"action": "pingwa_save", "_wpnonce": "bad",
                                                           "pingwa_hook_url": HOOK_URL})
    ok(status in (403, 200) and wp("option", "get", "pingwa_hook_url") == "", "save without a valid nonce stores nothing")
    anon = Browser()
    status, _, _ = anon.form("/wp-admin/admin-post.php", {"action": "pingwa_save", "pingwa_hook_url": HOOK_URL})
    ok(wp("option", "get", "pingwa_hook_url") == "", "save while logged out stores nothing")

    page = save(admin, HOOK_URL.replace("https://", "http://"), ["cf7", "woocommerce"])
    ok(notice(page)[0] == "error" and wp("option", "get", "pingwa_hook_url") == "", "http:// hook URL refused")
    page = save(admin, "https://hooks.pingwa-e2e.test/v1/notify", ["cf7", "woocommerce"])
    ok(notice(page)[0] == "error" and wp("option", "get", "pingwa_hook_url") == "", "non-hook path refused")
    page = save(admin, HOOK_URL, ["cf7", "woocommerce"])
    ok(notice(page)[0] == "success" and wp("option", "get", "pingwa_hook_url") == HOOK_URL, "hook URL saved")
    ok(TOKEN not in page and "hk_…5678" in page, "saved hook URL is shown masked only")
    autoload = wp("db", "query", "SELECT autoload FROM wp_options WHERE option_name='pingwa_hook_url'", "--skip-column-names")
    ok(autoload in ("off", "no"), f"hook URL option is not autoloaded ({autoload})")
    ok(len(captures()) == 0, "nothing posted before a trigger")

    # Send test (ruling 2): the server's HTTP status is shown.
    page = admin_page(admin)
    page = admin.form("/wp-admin/admin-post.php", {"action": "pingwa_test", "_wpnonce": nonce_for(page, "pingwa_test")})[2]
    kind, msg = notice(page)
    ok(kind == "success" and "HTTP 200" in msg, f"Send test shows the server's answer: {msg}")
    got = wait_captures(1)
    ok(len(got) == 1 and body(got[0])["source"] == "test", "test body posted")

    # Contact Form 7 submit.
    status, res, secs = cf7_submit(Browser(), form_id)
    ok(status == 200 and res.get("status") in ("mail_sent", "mail_failed"), f"CF7 submit went through ({res.get('status')}, {secs:.2f}s)")
    got = wait_captures(2)
    ok(len(got) == 2 and body(got[1])["source"] == "contact-form-7", "CF7 submission posted once")
    raw = got[1]["body"]
    ok("secret-ref-value" not in raw and '"Ref"' not in raw and "Your file" not in raw,
       "CF7 hidden and file fields not sent")

    # Block checkout through the Store API.
    status, res, secs = store_checkout(product_id)
    ok(status == 200 and res.get("order_id"), f"block checkout placed order {res.get('order_id')} ({res.get('status')}, {secs:.2f}s)")
    store_order = res["order_id"]
    got = wait_captures(3)
    ok(len(got) == 3 and body(got[2])["source"] == "woocommerce", "checkout order posted")
    raw = got[2]["body"]
    for secret in ("buyer-secret@example.com", "5550001111", "969 Market", "San Francisco", "94103"):
        ok(secret not in raw, f"order body has no customer contact detail ({secret})")
    ok(body(got[2])["number"] == str(store_order), "order body carries the order number")
    ok(wp_eval(f"echo wc_get_order( {store_order} )->get_meta( '_pingwa_notified' ) ? 'yes' : 'no';") == "yes",
       "order marked as delivered (order meta)")

    # Order made in code (REST/admin-style path): woocommerce_new_order.
    code_id = code_order(product_id)
    got = wait_captures(4)
    ok(len(got) == 4 and body(got[3])["number"] == str(code_id), f"order {code_id} made in code posted")
    ok("grace-secret@example.com" not in got[3]["body"] and "1 Navy Way" not in got[3]["body"], "code order body has no contact details")

    # An order made in wp-admin starts as an auto-draft and counts when it leaves it.
    draft_id = int(wp_eval(
        f"$o = wc_create_order( array( 'status' => 'auto-draft' ) ); $o->add_product( wc_get_product( {product_id} ), 3 );"
        "$o->calculate_totals(); $o->save(); echo $o->get_id();"))
    settle()
    ok(len(captures()) == 4, f"admin auto-draft order {draft_id} posts nothing yet")
    wp_eval(f"$o = wc_get_order( {draft_id} ); $o->set_status( 'pending' ); $o->save();")
    got = wait_captures(5)
    ok(len(got) == 5 and body(got[4])["number"] == str(draft_id), "admin order posted once it leaves auto-draft")
    base = 5

    # One delivery per order: status changes and saves send nothing.
    wp_eval(f"$o = wc_get_order( {store_order} ); $o->update_status( 'completed' ); $o->save();")
    wp_eval(f"$o = wc_get_order( {code_id} ); $o->update_status( 'on-hold' ); $o->add_order_note( 'x' ); $o->save();")
    wp_eval(f"$o = wc_get_order( {draft_id} ); $o->update_status( 'processing' ); $o->save();")
    settle()
    ok(len(captures()) == base, "status changes and edits post nothing")

    # Toggle: WooCommerce off → no post for a new order; CF7 still on.
    page = save(admin, "", ["cf7"])
    ok(wp("option", "get", "pingwa_hook_url") == HOOK_URL, "empty URL field keeps the saved hook URL")
    code_order(product_id)
    settle()
    ok(len(captures()) == base, "WooCommerce toggle off: new order posts nothing")
    page = save(admin, "", ["cf7", "woocommerce"])

    # A slow server never holds a checkout past the 3 s cap (ruling 5).
    subprocess.run(["docker", "compose", "up", "-d", "--force-recreate", "receiver"], cwd=HERE, check=True,
                   env={**os.environ, "RECEIVER_DELAY_S": "8"}, capture_output=True)
    time.sleep(2)
    status, res, secs = store_checkout(product_id)
    ok(status == 200 and res.get("order_id"), f"checkout with an 8 s slow server still succeeds ({secs:.2f}s)")
    ok(secs < 5.0, f"checkout waited at most the 3 s cap plus overhead ({secs:.2f}s)")
    got = wait_captures(base + 1)
    ok(len(got) == base + 1 and body(got[base])["number"] == str(res["order_id"]), "slow server still received the order")
    status, res, secs = cf7_submit(Browser(), form_id)
    ok(status == 200 and res.get("status") in ("mail_sent", "mail_failed") and secs < 5.0,
       f"CF7 submit with a slow server succeeds within the cap ({secs:.2f}s)")
    got = wait_captures(base + 2)
    ok(len(got) == base + 2, "slow server still received the form")

    # A dead server never breaks a checkout.
    subprocess.run(["docker", "stop", "pingwa-wp-receiver"], check=True, capture_output=True)
    status, res, secs = store_checkout(product_id)
    ok(status == 200 and res.get("order_id"), f"checkout with the server down still succeeds ({secs:.2f}s)")
    status, res, secs = cf7_submit(Browser(), form_id)
    ok(status == 200 and res.get("status") in ("mail_sent", "mail_failed"), f"CF7 with the server down still succeeds ({secs:.2f}s)")
    subprocess.run(["docker", "start", "pingwa-wp-receiver"], check=True, capture_output=True)

    # Server-minted hook URLs are accepted (written by check_bodies.py --mint).
    minted = (HERE / ".run" / "minted-urls.txt").read_text().split()
    php = "foreach ( array( " + ", ".join(f"'{u}'" for u in minted) + " ) as $u ) { echo Pingwa_Hook_Url::is_valid( $u ) ? 1 : 0; }"
    ok(wp_eval(php) == "1" * len(minted), f"{len(minted)} URLs minted by the server's own code are accepted")

    (HERE / ".run" / "drive-results.txt").write_text("\n".join(RESULTS) + "\n")
    print(f"drive: {len(RESULTS)} checks passed, {len(captures())} captures")


if __name__ == "__main__":
    main()
