#!/usr/bin/env bash
# ساخت فایل zip نصب افزونه OptiSnap از روی سورس مخزن
# Usage: bin/build-zip.sh  →  optisnap.zip (در ریشه مخزن، جایگزین نسخه قبلی)
# نیازمندی: python3 (یا zip/rsync در صورت وجود)
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
OUT="$ROOT/optisnap.zip"

if command -v zip >/dev/null 2>&1 && command -v rsync >/dev/null 2>&1; then
  STAGE="$(mktemp -d)"
  trap 'rm -rf "$STAGE"' EXIT
  mkdir -p "$STAGE/optisnap"
  rsync -a \
    --exclude '.git' --exclude '.github' --exclude 'docs' --exclude 'bin' \
    --exclude 'build' --exclude 'dist' --exclude 'optisnap.zip' \
    --exclude '.gitignore' --exclude 'README.md' --exclude 'CHANGELOG.md' \
    --exclude 'LICENSE' --exclude '*.sh' \
    ./ "$STAGE/optisnap/"
  cp readme.txt "$STAGE/optisnap/readme.txt"
  chmod 0755 "$STAGE"/optisnap/assets/bin/*/* 2>/dev/null || true
  rm -f "$OUT"
  (cd "$STAGE" && zip -qr "$OUT" optisnap)
else
  # Fallback: python3
  rm -f "$OUT"
  python3 - "$ROOT" "$OUT" << 'PYEOF'
import os, sys, zipfile

root, out = sys.argv[1], sys.argv[2]
# فقط پوشه‌های سطح بالای توسعه حذف می‌شوند (assets/bin باینری‌ها باید بمانند!)
EXCLUDE_DIRS = {'.git', '.github', 'docs', 'bin', 'build', 'dist', '__pycache__'}
EXCLUDE_FILES = {'.gitignore', 'README.md', 'CHANGELOG.md', 'LICENSE', 'optisnap.zip', 'readme.txt'}

def add(zf, disk_path, arc_path):
    # باینری‌های assets/bin باید مجوز اجرا داشته باشند
    info = zipfile.ZipInfo(arc_path)
    info.external_attr = (0o755 if '/assets/bin/' in arc_path else 0o644) << 16
    with open(disk_path, 'rb') as f:
        zf.writestr(info, f.read(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
    for dirpath, dirnames, filenames in os.walk(root):
        rel_dir = os.path.relpath(dirpath, root)
        if rel_dir == '.':
            dirnames[:] = [d for d in dirnames if d not in EXCLUDE_DIRS]
        # در زیرپوشه‌ها هیچ‌چیز حذف نمی‌شود (assets/bin حفظ شود)
        for fn in filenames:
            disk = os.path.join(dirpath, fn)
            rel = os.path.relpath(disk, root).replace(os.sep, '/')
            # فقط فایل‌های ریشه مخزن حذف می‌شوند؛ فایل‌های داخل assets و includes باید بمانند
            if '/' not in rel and (fn in EXCLUDE_FILES or fn.endswith('.sh')):
                continue
            add(zf, disk, 'optisnap/' + rel)
    # readme.txt استاندارد وردپرس
    rp = os.path.join(root, 'readme.txt')
    if os.path.exists(rp):
        add(zf, rp, 'optisnap/readme.txt')

print('built with python3')
PYEOF
fi

echo "✅ ساخته شد: $OUT"
echo "   حجم: $(du -h "$OUT" | cut -f1)"
