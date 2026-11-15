"""Check the captured bodies with the pingwa server's own code (read-only).

Run from the server repo, in its environment:

    cd <pingwa server repo> && uv run --frozen python <this file> <plugin repo>
    cd <pingwa server repo> && uv run --frozen python <this file> <plugin repo> --mint <n>

For each body the plugin posted (tests/e2e/.run/captures/*.json):
- a hook WITHOUT a template: hooks_api.render_text + clean, the exact path a real hook
  takes, must give a non-empty message, the body's `text`;
- orders: the `woocommerce_order` preset rendered over the body by
  hooks_api.rendered_message must fill every line of the preset;
- forms: the `form_generic` preset must fill the form name and the first fields.
Also: the plugin's join link is exactly what join.join_link("wp", number) builds, and
its QR is segno's QR of that link. --mint writes hook URLs made by the server's own
token code, for drive.py to feed to the plugin's validator.
"""

import asyncio
import io
import json
import re
import sys
from pathlib import Path

import segno

from pingwa_server import hook_templates, hooks_api, join
from pingwa_server.config import settings

FAILS: list[str] = []


def check(cond, what):
    print(("PASS " if cond else "FAIL ") + what)
    if not cond:
        FAILS.append(what)


def plain_render(body: bytes, ctype: str) -> str:
    return hooks_api.clean(asyncio.run(hooks_api.render_text(ctype, body)))


def preset_render(preset: str, body: bytes, ctype: str) -> str:
    payload = asyncio.run(hooks_api.template_payload(ctype, body))
    return hooks_api.rendered_message(hook_templates.PRESETS[preset].template, payload)


def mint(repo: Path, n: int):
    urls = [f"{settings.public_base_url.rstrip('/')}/h/{hooks_api.new_token()}" for _ in range(n)]
    for u in urls:
        assert hooks_api._TOKEN_RE.match(u.rsplit("/", 1)[1]), u
    (repo / "tests/e2e/.run/minted-urls.txt").write_text("\n".join(urls) + "\n")
    print(f"minted {n} hook URLs with hooks_api.new_token()")


def main():
    repo = Path(sys.argv[1]).resolve()
    if "--mint" in sys.argv:
        mint(repo, int(sys.argv[sys.argv.index("--mint") + 1]))
        return

    caps = sorted((repo / "tests/e2e/.run/captures").glob("[0-9]*.json"))
    check(len(caps) >= 4, f"{len(caps)} captured bodies")
    seen = set()
    for path in caps:
        rec = json.loads(path.read_text())
        raw = rec["body"].encode()
        ctype = rec["headers"].get("content-type", "")
        data = json.loads(raw)
        source = data.get("source")
        seen.add(source)
        name = f"{path.name} ({source})"
        check(ctype.startswith("application/json"), f"{name}: sent as JSON")
        check(len(raw) < 64 * 1024, f"{name}: under the server's 64 KB body limit")

        msg = plain_render(raw, ctype)
        check(bool(msg) and msg == hooks_api.clean(data["text"]),
              f"{name}: a hook without a template sends the body's text")
        print("  --- message (no template) ---\n  " + msg.replace("\n", "\n  "))

        if source == "woocommerce" and "more_orders" in data:
            out = preset_render("woocommerce_order", raw, ctype)
            check(msg.startswith(f"…and {data['more_orders']} more new order") and data["edit_url"] in msg,
                  f"{name}: the summary message names the count and links the orders")
            check(data["number"] in out, f"{name}: woocommerce_order preset shows the summary count")
            print("  --- message (woocommerce_order preset) ---\n  " + out.replace("\n", "\n  "))
            check(not re.search(r"@|\+1555", rec["body"]), f"{name}: no e-mail or phone anywhere")
        elif source == "woocommerce":
            out = preset_render("woocommerce_order", raw, ctype)
            first = data["line_items"][0]
            want = [
                f"🛒 New WooCommerce order #{data['number']}: {data['total']} {data['currency']}",
                f"Status: {data['status']}",
                f"Customer: {data['billing']['first_name']} {data['billing']['last_name']}",
                f"First item: {first['quantity']} × {first['name']}",
                f"Payment: {data['payment_method_title']}",
            ]
            # rstrip: the server trims the message, so an empty last field leaves "Payment:".
            check([l.rstrip() for l in out.split("\n")] == [l.rstrip() for l in want],
                  f"{name}: woocommerce_order preset fills every line")
            print("  --- message (woocommerce_order preset) ---\n  " + out.replace("\n", "\n  "))
            check(set(data["billing"]) == {"first_name", "last_name"}, f"{name}: billing has names only")
            check(not re.search(r"@|\+1555", rec["body"]), f"{name}: no e-mail or phone anywhere")
            check(not any(k in data for k in ("shipping", "email", "phone", "address")), f"{name}: no contact keys")
        elif source == "contact-form-7":
            out = preset_render("form_generic", raw, ctype)
            fields = data["data"]["fields"]
            want = [f"📝 {data['data']['formName']}: new response"] + [
                f"{f['label']}: {f['value']}" for f in fields[:3]]
            check(out.split("\n") == want, f"{name}: form_generic preset fills the form name and first fields")
            print("  --- message (form_generic preset) ---\n  " + out.replace("\n", "\n  "))

    check({"test", "contact-form-7", "woocommerce"} <= seen, "test, form and order bodies all seen")

    php = (repo / "pingwa.php").read_text()
    link = re.search(r"define\( 'PINGWA_JOIN_URL', '([^']+)' \);", php).group(1)
    number = "+" + re.search(r"wa\.me/(\d+)\?", link).group(1)
    check(link == join.join_link("wp", number), f"join link is the server's join_link('wp'): {link}")
    check(join.parse_join(urllib_unquote(link.split("text=", 1)[1])).tag == "wp", "the server reads it as join tag 'wp'")
    buf = io.BytesIO()
    segno.make_qr(link, error="M").save(buf, kind="svg", scale=6, border=2, dark="#000", light="#fff",
                                        xmldecl=False, title="join wp")
    check(buf.getvalue().decode() == (repo / "assets/join-wp-qr.svg").read_text(),
          "assets/join-wp-qr.svg is segno's QR of the join link")

    print(f"check_bodies: {len(FAILS)} failures")
    sys.exit(1 if FAILS else 0)


def urllib_unquote(s: str) -> str:
    from urllib.parse import unquote
    return unquote(s)


if __name__ == "__main__":
    main()
