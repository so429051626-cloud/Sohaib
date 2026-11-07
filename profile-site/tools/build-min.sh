#!/usr/bin/env bash
# يبني الملفات المضغوطة style.min.css و app.min.js ويضع في أولها بصمة الملف الأصلي.
# الصفحة تستعمل الملف المضغوط فقط لو كانت البصمة مطابقة للأصلي.
set -euo pipefail
cd "$(dirname "$0")/../assets"
for pair in "style.css:style.min.css" "app.js:app.min.js"; do
  src="${pair%%:*}"; out="${pair##*:}"
  hash=$(md5sum "$src" | cut -d' ' -f1)
  if [[ "$src" == *.css ]]; then
    body=$(npx --yes esbuild@0.24.0 "$src" --minify --log-level=warning)
  else
    body=$(npx --yes esbuild@0.24.0 "$src" --minify --target=es2015 --log-level=warning)
  fi
  printf '/*src:%s*/\n%s\n' "$hash" "$body" > "$out"
  echo "built $out (src $hash)"
done
