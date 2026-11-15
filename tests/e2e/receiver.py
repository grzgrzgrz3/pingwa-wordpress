"""Stand-in for the pingwa server's hook endpoint (E2E only).

HTTPS on :443. POST /h/hk_<32> -> 200 {"accepted": true, "id": "e2e-<n>"}; anything else
-> 404 like an unknown hook. Every request is written to /captures/<n>.json (method,
path, headers, body) as soon as it is read, before the optional answer delay
(RECEIVER_DELAY_S), so a slow server can be simulated.
"""

import json
import os
import re
import ssl
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN = re.compile(r"^/h/hk_[A-Za-z0-9_-]{32}$")
DELAY = float(os.environ.get("RECEIVER_DELAY_S", "0") or 0)
COUNT = [len([f for f in os.listdir("/captures") if f.endswith(".json") and f[0].isdigit()])]  # survives a restart


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):  # noqa: N802
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length).decode("utf-8", "replace")
        COUNT[0] += 1
        n = COUNT[0]
        record = {"n": n, "at": time.time(), "method": "POST", "path": self.path,
                  "headers": {k.lower(): v for k, v in self.headers.items()}, "body": body}
        tmp = f"/captures/.{n:03d}.json"
        with open(tmp, "w") as f:
            json.dump(record, f, ensure_ascii=False, indent=1)
        os.replace(tmp, f"/captures/{n:03d}.json")
        print(f"capture {n}: {self.path[:8]}… {len(body)} bytes", flush=True)
        if DELAY:
            time.sleep(DELAY)
        if TOKEN.match(self.path):
            self._answer(200, {"accepted": True, "id": f"e2e-{n}"})
        else:
            self._answer(404, {"detail": "Not Found"})

    def _answer(self, code, obj):
        data = json.dumps(obj).encode()
        try:
            self.send_response(code)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError):
            pass  # a non-blocking sender hung up before the answer

    def log_message(self, *args):
        pass


ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain("/certs/server.pem", "/certs/server.key")
httpd = ThreadingHTTPServer(("0.0.0.0", 443), Handler)
httpd.socket = ctx.wrap_socket(httpd.socket, server_side=True)
print("receiver on :443", flush=True)
httpd.serve_forever()
