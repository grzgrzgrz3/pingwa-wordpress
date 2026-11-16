#!/usr/bin/env python3
"""Screenshot the real Settings -> pingwa page of the Docker E2E stack (KEEP=1 tests/e2e/run.sh).

    python3 bin/store-assets/shoot-settings.py

Writes .wordpress-org/screenshot-1.png (the whole page in wp-admin, after a saved hook URL and
a Send test) and bin/store-assets/real/join-crop.png (the "1. Get a hook URL" part with the
join button and the QR code). The site is renamed to a made-up shop first; the hook URL is the
E2E stand-in's, shown masked by the page itself. Docker resources are pingwa-wp-*.
"""
import re
import subprocess
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
BASE = "http://127.0.0.1:18471"
HOOK = "https://hooks.pingwa-e2e.test/h/hk_PingwaWordPressE2eToken012345678"


def wp(*args):
    return subprocess.run(["docker", "exec", "-i", "pingwa-wp-cli", "wp", *args], check=True, capture_output=True, text=True).stdout


def main():
    wp("option", "update", "blogname", "Lakeside Goods")
    (HERE / "real").mkdir(exist_ok=True)
    with sync_playwright() as p:
        b = p.chromium.launch()
        pg = b.new_context(viewport={"width": 1280, "height": 1000}, device_scale_factor=1).new_page()
        pg.goto(f"{BASE}/wp-login.php")
        pg.fill("#user_login", "admin")
        pg.fill("#user_pass", "admin")
        pg.click("#wp-submit")
        pg.goto(f"{BASE}/wp-admin/options-general.php?page=pingwa")
        pg.fill("#pingwa_hook_url", HOOK)
        pg.click("input[value='Save settings']")
        pg.wait_for_load_state("networkidle")
        pg.click("input[value='Send test']")
        pg.wait_for_load_state("networkidle")
        # other plugins' notices are not ours to show
        pg.add_style_tag(content=".notice:not(.pingwa-notice):not(.updated):not(.notice-success):not(.notice-error),.woocommerce-message,#wpfooter{display:none !important}")
        pg.evaluate("document.fonts.ready")
        wrap = pg.locator("#wpbody-content")
        box = wrap.bounding_box()
        h = int(min(box["y"] + box["height"], 1500))
        pg.set_viewport_size({"width": 1280, "height": h + 20})
        pg.wait_for_timeout(300)
        out = ROOT / ".wordpress-org/screenshot-1.png"
        pg.screenshot(path=str(out), clip={"x": 0, "y": 0, "width": 1280, "height": h})
        print(out, 1280, h)
        # the join part, again at device scale 2 (it is shown small in the screenshot composite)
        pg2 = b.new_context(viewport={"width": 1280, "height": h + 20}, device_scale_factor=2).new_page()
        pg2.goto(f"{BASE}/wp-login.php")
        pg2.fill("#user_login", "admin")
        pg2.fill("#user_pass", "admin")
        pg2.click("#wp-submit")
        pg2.goto(f"{BASE}/wp-admin/options-general.php?page=pingwa")
        pg2.add_style_tag(content=".notice:not(.pingwa-notice),.woocommerce-message{display:none !important}")
        pg2.wait_for_timeout(300)
        top = pg2.locator("h2", has_text="1. Get a hook URL").bounding_box()
        fig = pg2.locator("figure").first.bounding_box()
        x0, y0 = top["x"] - 14, top["y"] - 14
        clip = {"x": x0, "y": y0, "width": fig["x"] + fig["width"] + 18 - x0, "height": fig["y"] + fig["height"] + 3 - y0}
        crop = HERE / "real/join-crop.png"
        pg2.screenshot(path=str(crop), clip=clip)
        print(crop, clip)
        b.close()


if __name__ == "__main__":
    sys.exit(main())
