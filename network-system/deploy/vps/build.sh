#!/usr/bin/env bash
# Builds the single-file server installer with the system files inside it.
#   bash network-system/deploy/vps/build.sh [output-file]
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(git -C "$HERE" rev-parse --show-toplevel)
OUT=${1:-$ROOT/network-apps/dist/madix-nexus-vps.sh}
REV=$(git -C "$ROOT" rev-parse --short=8 HEAD)
VERSION="$(git -C "$ROOT" log -1 --format=%cd --date=format:%Y.%m.%d HEAD)-$REV"

# نسخ JavaScript الإنجليزية يجب أن تكون مولّدة من آخر نسخة للقاموس والملفات
php "$ROOT/network-system/cli/i18n.php" check || { echo "run: php network-system/cli/i18n.php js, then commit" >&2; exit 1; }

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

git -C "$ROOT" archive --format=tar HEAD:network-system | tar -x -C "$WORK"
mkdir -p "$WORK/sys"
find "$WORK" -mindepth 1 -maxdepth 1 ! -name sys -exec mv {} "$WORK/sys/" \;
rm -rf "$WORK/sys/tests" "$WORK/sys/deploy/vps/dist"
rm -f "$WORK/sys"/config/config*.php

tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner -C "$WORK/sys" -cf - . | gzip -9n >"$WORK/payload.tgz"
SHA=$(sha256sum "$WORK/payload.tgz" | awk '{print $1}')

mkdir -p "$(dirname "$OUT")"
sed -e "s/__MADIX_VERSION__/$VERSION/" -e "s/__MADIX_SHA256__/$SHA/" "$HERE/install.sh" >"$OUT.tmp"
{
    printf '\n__MADIX_PAYLOAD_BELOW__\n'
    base64 -w 76 "$WORK/payload.tgz"
} >>"$OUT.tmp"
chmod 755 "$OUT.tmp"
mv -f "$OUT.tmp" "$OUT"
echo "$OUT  ($VERSION, $(du -h "$OUT" | awk '{print $1}'))"
