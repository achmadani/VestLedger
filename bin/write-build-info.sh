#!/usr/bin/env bash
#
# Menulis writable/build.json berisi commit dan waktu build.
#
# Dijalankan saat build/release, BUKAN saat runtime: server produksi sering
# tidak punya direktori .git, dan memanggil git pada tiap request tidak pantas.

set -euo pipefail

cd "$(dirname "$0")/.."

COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
BUILT_AT="$(date '+%Y-%m-%d %H:%M:%S')"

mkdir -p writable
printf '{\n  "commit": "%s",\n  "built_at": "%s"\n}\n' "$COMMIT" "$BUILT_AT" > writable/build.json

echo "build.json: ${COMMIT:0:7} @ $BUILT_AT"
