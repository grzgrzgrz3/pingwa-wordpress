#!/usr/bin/env bash
# Build the distributable plugin: build/pingwa/ (what WordPress installs) and
# build/pingwa.zip (what goes to WordPress.org). Only runtime files, no tests or tooling.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build
mkdir -p build/pingwa
cp -r pingwa.php uninstall.php readme.txt includes assets build/pingwa/
( cd build && python3 -c '
import os, zipfile
with zipfile.ZipFile("pingwa.zip", "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk("pingwa"):
        for f in sorted(files):
            z.write(os.path.join(root, f))
' )
echo "build/pingwa.zip:"; python3 -c 'import zipfile; [print("  " + n) for n in zipfile.ZipFile("build/pingwa.zip").namelist()]'
