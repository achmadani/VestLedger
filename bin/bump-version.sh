#!/usr/bin/env bash
#
# Menaikkan nomor versi dan mencatat metadata build.
#
# Dipakai oleh `make release` dan oleh hook pre-push, sehingga setiap push
# selalu membawa nomor versi yang baru.
#
# Pemakaian: bin/bump-version.sh [patch|minor|major]

set -euo pipefail

cd "$(dirname "$0")/.."

PART="${1:-patch}"
VERSION_FILE="VERSION"

[ -f "$VERSION_FILE" ] || echo "0.0.0" > "$VERSION_FILE"

CURRENT="$(tr -d '[:space:]' < "$VERSION_FILE")"
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"

MAJOR="${MAJOR:-0}"; MINOR="${MINOR:-0}"; PATCH="${PATCH:-0}"

case "$PART" in
  major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
  minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
  patch) PATCH=$((PATCH + 1)) ;;
  *) echo "Bagian versi tidak dikenali: $PART (gunakan patch|minor|major)" >&2; exit 1 ;;
esac

NEW="${MAJOR}.${MINOR}.${PATCH}"
echo "$NEW" > "$VERSION_FILE"

echo "$CURRENT -> $NEW"
