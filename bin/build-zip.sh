#!/usr/bin/env bash
# Builds colorbox-google-media-<version>.zip for Plugins → Add New → Upload Plugin (no development files).
# Usage: bin/build-zip.sh [output directory, default dist/]
set -euo pipefail
OUT=$(realpath -m "${1:-$(dirname "$0")/../dist}")
cd "$(dirname "$0")/.."
VERSION=$(sed -n "s/^const VERSION *= *'\(.*\)';/\1/p" colorbox-google-media.php)
mkdir -p "$OUT"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir "$TMP/colorbox-google-media"
cp -r colorbox-google-media.php includes assets languages README.md README.pl.md LICENSE "$TMP/colorbox-google-media/"
(cd "$TMP" && python3 -m zipfile -c "colorbox-google-media-$VERSION.zip" colorbox-google-media)
mv "$TMP/colorbox-google-media-$VERSION.zip" "$OUT/"
echo "$OUT/colorbox-google-media-$VERSION.zip"
