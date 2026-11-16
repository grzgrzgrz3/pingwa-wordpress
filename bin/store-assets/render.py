#!/usr/bin/env python3
"""Render the store listing images (WordPress.org and the Shopify App Store).

    python3 bin/store-assets/render.py wp                  # -> .wordpress-org/
    python3 bin/store-assets/render.py shopify OUT_DIR     # -> OUT_DIR/*.png (needs the server repo, read-only)

Brand: the pingwa mark and the landing's palette and fonts (Fraunces, Newsreader, IBM Plex
Mono; fetched from Google Fonts while rendering). No Meta, WhatsApp or Shopify logos.
The chat mocks are WhatsApp-style (bubbles, a header, a date chip) in the brand palette.
Sample data only: a made-up shop, a made-up customer. Needs python3 + playwright (chromium);
`uvx --from segno==1.6.6 segno` for the Shopify join QR.

The real settings page screenshots (`.wordpress-org/screenshot-1.png`, `real/join-crop.png`)
come from shoot-settings.py against the Docker E2E stack.
"""
import base64
import html
import json
import re
import subprocess
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
SERVER = Path.home() / "git/pingwa/server"
MARK = (HERE / "logo-mark.svg").read_text()
MARK = re.sub(r"<!--.*?-->", "", MARK, flags=re.S)

FONTS = ('<link rel="preconnect" href="https://fonts.googleapis.com">'
         '<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,700;0,9..144,900;1,9..144,600'
         '&family=Newsreader:opsz,wght@6..72,400;6..72,500&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">')

CSS = """
:root{--paper:#E7DEC8;--card:#F0E9D6;--ink:#20201B;--ink-soft:#54524A;--green:#116B45;--amber:#D9930D;
--sky:#CFE6F3;--tip:#FBFDFE;--tip-shade:#E3F1F9;--igloo:#A9D4EC;--water-1:#11426A;--water-2:#0B2E4D;--bed:#04121F;
--line:#C9DEEB;--surface:#FBFDFE;--surface-2:#EDF5FA;--field:#6A889D;--green-ink:#116B45;--on-water:#EAF3F9;
--fd:"Fraunces",Georgia,serif;--fb:"Newsreader",Georgia,serif;--fm:"IBM Plex Mono",ui-monospace,monospace;
--sans:-apple-system,"Segoe UI",Roboto,"Liberation Sans",Helvetica,Arial,sans-serif}
*{margin:0;padding:0;box-sizing:border-box}
html,body{background:var(--sky);color:var(--ink);font-family:var(--fb);overflow:hidden}
svg{display:block}
.eyebrow{font-family:var(--fm);font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-soft)}
.h{font-family:var(--fd);font-weight:900;letter-spacing:-.025em;line-height:1.04}
.h em{font-style:italic;font-weight:700;color:var(--green)}
/* the iceberg tip (the landing's polygon) */
.tip{position:absolute;background:var(--tip);clip-path:polygon(0 100%,7% 62%,20% 74%,38% 16%,50% 34%,63% 0,82% 44%,91% 36%,100% 100%)}
.tip::after{content:"";position:absolute;inset:0;background:var(--tip-shade);clip-path:polygon(63% 0,82% 44%,91% 36%,100% 100%,58% 100%)}
.water{position:absolute;left:0;right:0;bottom:0;background:var(--water-1)}
.wave{position:absolute;left:0;right:0;width:100%;height:26px}
.wave path{fill:var(--water-1)}
/* chat mock */
.phone{position:absolute;width:400px;border:10px solid var(--ink);border-radius:46px;background:var(--paper);overflow:hidden;
  box-shadow:0 30px 60px -24px rgba(17,66,106,.55)}
.phone .bar{background:var(--green);color:var(--paper);display:flex;align-items:center;gap:11px;padding:30px 16px 12px;font-family:var(--sans)}
.phone .bar .av{width:38px;height:38px;border-radius:50%;background:var(--paper);overflow:hidden;flex:none;display:flex;align-items:flex-end;justify-content:center}
.phone .bar .av svg{width:34px;height:34px;margin-bottom:-3px}
.phone .bar b{font-size:17px;font-weight:600;display:block;line-height:1.2}
.phone .bar small{font-size:12px;opacity:.8}
.phone .bar .notch{position:absolute;top:8px;left:50%;margin-left:-45px;width:90px;height:20px;border-radius:12px;background:var(--ink)}
.chat{padding:16px 12px 120px;display:flex;flex-direction:column;gap:6px;font-family:var(--sans)}
.phone .bb{font-size:16.5px;max-width:94%}
.chip{align-self:center;background:var(--card);color:var(--ink-soft);font-size:12px;padding:4px 11px;border-radius:9px;margin-bottom:6px}
.bb{max-width:88%;padding:8px 11px 6px;border-radius:12px;font-size:15px;line-height:1.32;white-space:pre-wrap;overflow-wrap:anywhere}
.bb.in{background:var(--surface);align-self:flex-start;border-top-left-radius:3px}
.bb.out{background:#CBDCC0;align-self:flex-end;border-top-right-radius:3px}
.bb time{display:block;text-align:right;font-size:11px;color:var(--ink-soft);margin-top:2px}
.bb a{color:var(--green-ink);text-decoration:none;word-break:break-all}
.field{position:absolute;left:0;right:0;bottom:0;padding:10px 12px 18px;background:var(--paper);display:flex;gap:8px;font-family:var(--sans)}
.field span{flex:1;background:var(--surface);border-radius:22px;padding:10px 16px;font-size:15px;color:var(--ink)}
.field i{width:42px;height:42px;border-radius:50%;background:var(--green);flex:none}
"""


def page(body, extra=""):
    return f"<!doctype html><html><head><meta charset=utf-8>{FONTS}<style>{CSS}{extra}</style></head><body>{body}</body></html>"


def tip(style):
    return f'<div class="tip" style="{style}"></div>'


def esc(s):
    return html.escape(s, quote=False)


def bubble(text, kind="in", time="9:41", link=None):
    body = esc(text)
    if link:
        body = body.replace(esc(link), f'<a>{esc(link)}</a>')
    return f'<div class="bb {kind}">{body}<time>{time}</time></div>'


def phone(bubbles, style, field=None):
    f = f'<div class="field"><span>{esc(field)}</span><i></i></div>' if field is not None else ""
    return (f'<div class="phone" style="{style}"><div class="bar"><div class="notch"></div>'
            f'<div class="av">{MARK}</div><div><b>pingwa</b><small>business account</small></div></div>'
            f'<div class="chat"><div class="chip">Today</div>{"".join(bubbles)}</div>{f}</div>')


# ---- sample messages: the exact format the plugin / the Shopify app writes -------------------
ORDER = ("🛒 New order #1042 on Lakeside Goods: 48.50 EUR\nStatus: processing\n"
         "Items: 2 × Linen tote bag, 1 × Ceramic mug\nCustomer: Ada Lovelace\nPayment: Direct bank transfer\n"
         "https://lakeside.example/wp-admin/admin.php?page=wc-orders&action=edit&id=1042")
FORM = ("📝 Contact on Lakeside Goods: new response\nYour name: Ada Lovelace\nYour email: ada@example.com\n"
        "Topic: Wholesale\nYour message: Hello, do you ship 40 mugs to Hamburg? Thanks!")
SHOPIFY_ORDER = "🛒 New Shopify order #1042: 48.50 EUR\nFirst item: 1 × Linen tote bag"


# ---- icon ----------------------------------------------------------------------------------
def icon_html(pad_pct):
    w = 100 - 2 * pad_pct
    return page(f'''<div style="position:absolute;inset:0;background:var(--sky)"></div>
{tip(f"left:{pad_pct - 4}%;right:{pad_pct - 4}%;bottom:{pad_pct - 2}%;height:44%")}
<div style="position:absolute;left:{pad_pct}%;width:{w}%;bottom:{pad_pct}%">{MARK}</div>''')


# ---- WordPress.org banners (design at 772x250; the 1544x500 is the same at device scale 2) ---
def banner_html():
    return page(f'''
{tip("left:18px;bottom:24px;width:236px;height:182px")}
<div style="position:absolute;left:78px;bottom:112px;width:112px">{MARK}</div>
<div class="water" style="height:26px"></div>
<svg class="wave" style="bottom:24px" viewBox="0 0 1200 26" preserveAspectRatio="none"><path d="M0 14c50-12 100-12 150 0s100 12 150 0 100-12 150 0 100 12 150 0 100-12 150 0 100 12 150 0 100-12 150 0 100 12 150 0v12H0z"/></svg>
<div style="position:absolute;left:258px;top:34px;width:290px">
  <div class="eyebrow" style="font-size:9.5px">pingwa · order and form alerts</div>
  <div class="h" style="font-size:28px;margin-top:8px">Orders and forms, <em>on WhatsApp.</em></div>
  <div style="font-size:12.5px;line-height:1.4;margin-top:9px;color:var(--ink-soft)">WooCommerce, Contact Form 7, WPForms, Elementor Pro and Gravity Forms. No Meta setup.</div>
</div>
<div style="position:absolute;right:10px;top:30px;width:200px;display:flex;flex-direction:column;gap:7px;font-family:var(--sans)">
  <div class="bb in" style="max-width:none;font-size:11px;padding:7px 10px 5px;box-shadow:0 1px 0 rgba(17,66,106,.12)">🛒 New order #1042: 48.50 EUR<br>Items: 2 × Linen tote bag<time>9:41</time></div>
  <div class="bb in" style="max-width:none;font-size:11px;padding:7px 10px 5px;box-shadow:0 1px 0 rgba(17,66,106,.12);margin-left:14px">📝 Contact: new response<br>Your name: Ada Lovelace<time>9:43</time></div>
</div>''')


# ---- WordPress.org screenshots 2-4 (1280x800) ---------------------------------------------
def shot_html(eyebrow, headline, sub, bubbles, phone_h=520, field=None, left_extra=""):
    return page(f'''
{tip("left:-40px;bottom:-30px;width:640px;height:330px;opacity:.9")}
<div style="position:absolute;left:84px;top:150px;width:560px">
  <div class="eyebrow" style="font-size:13px">{eyebrow}</div>
  <div class="h" style="font-size:58px;margin-top:14px">{headline}</div>
  <p style="font-size:22px;line-height:1.45;margin-top:20px;color:var(--ink-soft);max-width:30em">{sub}</p>
  {left_extra}
</div>
{phone(bubbles, f"right:130px;top:{(800 - phone_h) // 2}px;height:{phone_h}px", field)}''')


# ---- the Shopify ones (1600x900) -----------------------------------------------------------
def caption_shot(eyebrow, headline, sub, right, water=True):
    band = '<div style="position:absolute;left:0;right:0;bottom:0;height:150px;background:var(--water-1)"></div>' if water else ""
    return page(f'''
{band}
<div style="position:absolute;left:96px;top:236px;width:600px">
  <div class="eyebrow" style="font-size:15px">{eyebrow}</div>
  <div class="h" style="font-size:66px;margin-top:16px">{headline}</div>
  <p style="font-size:25px;line-height:1.45;margin-top:22px;color:var(--ink-soft)">{sub}</p>
</div>
{right}''')


def framed(img_b64, left, top, width):
    return (f'<img src="data:image/png;base64,{img_b64}" style="position:absolute;left:{left}px;top:{top}px;width:{width}px;'
            'border-radius:22px;box-shadow:0 1px 0 rgba(17,66,106,.05),0 24px 56px -22px rgba(17,66,106,.5);border:1px solid #C9DEEB">')


def b64(path):
    return base64.b64encode(Path(path).read_bytes()).decode()


def settle(pg):
    pg.wait_for_load_state("networkidle")
    pg.evaluate("document.fonts.ready")
    pg.wait_for_function("document.fonts.status === 'loaded'")
    pg.wait_for_timeout(200)


def shoot(pg, html_text, out, w, h, scale=1):
    out = Path(out)
    out.parent.mkdir(parents=True, exist_ok=True)
    pg.set_viewport_size({"width": w, "height": h})
    pg.set_content(html_text)
    settle(pg)
    pg.screenshot(path=str(out))
    print(f"{out} {w * scale}x{h * scale}")


def make_wp(browser):
    out = ROOT / ".wordpress-org"
    for scale, name in ((1, "icon-128x128.png"), (2, "icon-256x256.png")):
        ctx = browser.new_context(viewport={"width": 128, "height": 128}, device_scale_factor=scale)
        shoot(ctx.new_page(), icon_html(12), out / name, 128, 128, scale)
        ctx.close()
    for scale, name in ((1, "banner-772x250.png"), (2, "banner-1544x500.png")):
        ctx = browser.new_context(viewport={"width": 772, "height": 250}, device_scale_factor=scale)
        shoot(ctx.new_page(), banner_html(), out / name, 772, 250, scale)
        ctx.close()
    ctx = browser.new_context(viewport={"width": 1280, "height": 800})
    pg = ctx.new_page()
    shoot(pg, shot_html("WooCommerce · new order", "Every new order, <em>on your phone.</em>",
                        "One WhatsApp message per order: number, total, items, the customer’s name and a link to the order. "
                        "Never the customer’s e-mail, phone or address.",
                        [bubble(ORDER, "in", "9:41", "https://lakeside.example/wp-admin/admin.php?page=wc-orders&action=edit&id=1042")], phone_h=560),
          out / "screenshot-2.png", 1280, 800)
    shoot(pg, shot_html("Contact Form 7 · WPForms · Elementor Pro · Gravity Forms", "Every form message, <em>too.</em>",
                        "The form title and each field’s label and value. Passwords, uploads, hidden fields and spam traps are never sent.",
                        [bubble(FORM, "in", "9:43")], phone_h=470),
          out / "screenshot-3.png", 1280, 800)
    join = framed(b64(HERE / "real/join-crop.png"), 84, 380, 560)
    shoot(pg, shot_html("Set up · once", "Text <em>join wp</em> to pingwa.",
                        "Scan the code or tap the button on Settings → pingwa: WhatsApp opens with the message ready to send. "
                        "Your phone is the one that gets the alerts.",
                        [], phone_h=420, field="join wp").replace('top:150px;width:560px', 'top:96px;width:560px')
          .replace("</body>", join + "</body>"),
          out / "screenshot-4.png", 1280, 800)
    ctx.close()


# ---- Shopify -----------------------------------------------------------------------------
STATUS_UNLINKED = {"shop": "lakeside-goods.myshopify.com", "linked": False, "needs_auth": False}
STATUS_LINKED = {"shop": "lakeside-goods.myshopify.com", "linked": True, "needs_auth": False, "hook_active": True,
                 "number": "+44 7700 900123", "topic": "orders/create", "send_to": ["me", "m1"],
                 "members": [{"id": "m1", "name": "Alex"}, {"id": "m2", "name": "Sam"}, {"id": "m3", "name": "Jo"}]}


def join_qr(url):
    import tempfile
    with tempfile.TemporaryDirectory() as d:
        f = Path(d) / "qr.svg"
        subprocess.run(["uvx", "--from", "segno==1.6.6", "segno", "--error", "M", "--scale", "6", "--border", "2",
                        "--dark", "#000", "--light", "#fff", "--no-xmldecl", "--output", str(f), url],
                       check=True, capture_output=True, text=True)
        return "data:image/svg+xml;base64," + base64.b64encode(f.read_bytes()).decode()


def embedded_page(browser, status, tmp):
    """The server's real shopify-app.html with sample data; the page's own API call is answered
    from memory. Returns (png path of the app column without the footnote, its size)."""
    part = SERVER / "web/partials"
    src = (SERVER / "web/shopify-app.html").read_text()
    # theme.js and theme.css have the palette; app.css not used by this page
    tok = {"SITE_CSS": (part / "site.css").read_text(), "PAGE_CSS": (part / "page.css").read_text(),
           "THEME_CSS": (part / "theme.css").read_text(), "THEME_JS": (part / "theme.js").read_text(),
           "SHOPIFY_API_KEY": "sample", "SHOPIFY_MODE": "app", "SHOPIFY_INSTALL_URL": "", "JOIN_QR": join_qr("https://pingwa.dev/join/shopify"),
           "SHOPIFY_JOIN_URL": "https://pingwa.dev/join/shopify"}
    for k, v in tok.items():
        src = src.replace("{{" + k + "}}", v)
    src = src.replace('<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>', "")
    ctx = browser.new_context(viewport={"width": 752, "height": 1000}, device_scale_factor=2)
    pg = ctx.new_page()
    pg.route("**/shopify/api/status", lambda r: r.fulfill(status=200, content_type="application/json", body=json.dumps(status)))
    pg.route("**/brand/favicon.svg", lambda r: r.fulfill(status=200, content_type="image/svg+xml", body=(HERE.parent.parent / "bin/store-assets/logo-mark.svg").read_text()))
    pg.route("http://sample.test/", lambda r: r.fulfill(status=200, content_type="text/html", body=src))
    pg.goto("http://sample.test/")
    pg.wait_for_function("document.getElementById('loading').hidden")
    settle(pg)
    pg.add_style_tag(content="html,body{overflow:visible}")
    # the footnote carries the pricing sentence: no pricing in listing images
    pg.evaluate("document.querySelector('.note').remove()")
    box = pg.evaluate("""(()=>{const a=document.querySelector('.app').getBoundingClientRect();
      let b=0;document.querySelectorAll('.card').forEach(c=>{if(!c.hidden){b=Math.max(b,c.getBoundingClientRect().bottom)}});
      return {x:a.left,w:a.width,h:b+22}})()""")
    out = tmp / f"page-{'linked' if status['linked'] else 'unlinked'}.png"
    pg.screenshot(path=str(out), clip={"x": box["x"], "y": 0, "width": box["w"], "height": box["h"]})
    ctx.close()
    return out, box


def make_shopify(browser, out_dir):
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    tmp = out / ".work"
    tmp.mkdir(exist_ok=True)
    ctx = browser.new_context(viewport={"width": 1200, "height": 1200})
    shoot(ctx.new_page(), icon_html(15), out / "app-icon-1200x1200.png", 1200, 1200)
    ctx.close()

    ctx = browser.new_context(viewport={"width": 1600, "height": 900})
    pg = ctx.new_page()
    # 1: the app page, not linked yet
    unl, ub = embedded_page(browser, STATUS_UNLINKED, tmp)
    h = 900 - 2 * 56
    w = int(ub["w"] * h / ub["h"])
    shoot(pg, caption_shot("Link · one time", "Pick the number that gets the alerts.",
                           "Choose when pingwa sends, link a pingwa number and you are done.",
                           framed(b64(unl), 1600 - 96 - w, 56, w), water=False), out / "screenshot-1-link.png", 1600, 900)
    # 2: linked: trigger and recipients
    lnk, lb = embedded_page(browser, STATUS_LINKED, tmp)
    h = 900 - 2 * 56
    w = int(lb["w"] * h / lb["h"])
    shoot(pg, caption_shot("Settings", "You, or your whole team.",
                           "Alert on a placed order or a paid one, and choose who gets the message.",
                           framed(b64(lnk), 1600 - 96 - w, 56, w), water=False), out / "screenshot-2-settings.png", 1600, 900)
    # 3: the alert on a phone
    shoot(pg, caption_shot("The alert", "Order number, total, first item.",
                           "One message per order. No customer name, e-mail, phone or address.",
                           phone([bubble(SHOPIFY_ORDER, "in", "9:41")], "right:150px;top:150px;height:520px")),
          out / "screenshot-3-alert.png", 1600, 900)
    # 4: the link step for people without an account: the join code
    unl_crop = tmp / "join-card.png"
    shoot_join_card(browser, unl_crop)
    shoot(pg, caption_shot("No pingwa account yet", "One message gets you in.",
                           "Scan the code or open the link, then link the new account in the app.",
                           framed(b64(unl_crop), 1600 - 96 - 640, 150, 640)
                           + phone([bubble("join shopify", "out", "9:38")], "right:230px;top:470px;height:340px;width:340px")),
          out / "screenshot-4-join.png", 1600, 900)
    # feature image
    shoot(pg, page(f'''
{tip("left:560px;bottom:150px;width:760px;height:560px")}
<div style="position:absolute;left:785px;bottom:400px;width:300px">{MARK}</div>
<div class="water" style="height:150px"></div>
<svg class="wave" style="bottom:148px;height:40px" viewBox="0 0 1200 26" preserveAspectRatio="none"><path d="M0 14c50-12 100-12 150 0s100 12 150 0 100-12 150 0 100 12 150 0 100-12 150 0 100 12 150 0 100-12 150 0 100 12 150 0v12H0z"/></svg>
<div class="eyebrow" style="position:absolute;left:0;right:0;top:44px;text-align:center;font-size:18px">pingwa order alerts</div>
<div class="h" style="position:absolute;left:0;right:0;top:76px;text-align:center;font-size:84px">Your shop texts <em>you.</em></div>
<div style="position:absolute;left:112px;top:420px;display:flex;flex-direction:column;gap:12px;font-family:var(--sans);width:420px">
  <div class="bb in" style="font-size:22px;padding:14px 18px 10px;border-radius:20px;border-top-left-radius:5px">🛒 New order #1042: 48.50 EUR<time style="font-size:14px">9:41</time></div>
  <div class="bb in" style="font-size:22px;padding:14px 18px 10px;border-radius:20px;border-top-left-radius:5px;margin-left:36px">🛒 New order #1043: 19.00 EUR<time style="font-size:14px">9:52</time></div>
</div>''', ""), out / "feature-image-1600x900.png", 1600, 900)
    ctx.close()


def shoot_join_card(browser, dest):
    """The embedded page's join card ("No pingwa account yet?"), real markup, sample QR."""
    part = SERVER / "web/partials"
    src = (SERVER / "web/shopify-app.html").read_text()
    tok = {"SITE_CSS": (part / "site.css").read_text(), "PAGE_CSS": (part / "page.css").read_text(),
           "THEME_CSS": (part / "theme.css").read_text(), "THEME_JS": (part / "theme.js").read_text(),
           "SHOPIFY_API_KEY": "sample", "SHOPIFY_MODE": "app", "SHOPIFY_INSTALL_URL": "",
           "JOIN_QR": join_qr("https://pingwa.dev/join/shopify"), "SHOPIFY_JOIN_URL": "https://pingwa.dev/join/shopify"}
    for k, v in tok.items():
        src = src.replace("{{" + k + "}}", v)
    src = src.replace('<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>', "")
    ctx = browser.new_context(viewport={"width": 752, "height": 1000}, device_scale_factor=2)
    pg = ctx.new_page()
    pg.route("**/shopify/api/status", lambda r: r.fulfill(status=200, content_type="application/json", body=json.dumps(STATUS_UNLINKED)))
    pg.route("**/brand/favicon.svg", lambda r: r.fulfill(status=200, content_type="image/svg+xml", body=MARK))
    pg.route("http://sample.test/", lambda r: r.fulfill(status=200, content_type="text/html", body=src))
    pg.goto("http://sample.test/")
    pg.wait_for_function("document.getElementById('loading').hidden")
    settle(pg)
    pg.locator("#join").screenshot(path=str(dest))
    ctx.close()


def main():
    if len(sys.argv) < 2 or sys.argv[1] not in ("wp", "shopify"):
        sys.exit(__doc__)
    with sync_playwright() as p:
        browser = p.chromium.launch()
        if sys.argv[1] == "wp":
            make_wp(browser)
        else:
            make_shopify(browser, sys.argv[2] if len(sys.argv) > 2 else "/tmp/shopify-store-assets")
        browser.close()


if __name__ == "__main__":
    main()
