#!/usr/bin/env bash
# Build the SubjectsPlus stable release archives.
#
#   build/package-release.sh            # version read from control/includes/version.php
#   build/package-release.sh 4.6.0      # or given explicitly
#
# Output (in dist/):
#   SubjectsPlus-<version>-stable.zip
#   SubjectsPlus-<version>-stable.tar.gz
#   SHA256SUMS.txt
#
# The archives contain only what a web server needs: the tracked source tree (git archive when run
# in a git checkout, so local config, uploads and caches never leak in), without .git/, .github/,
# node_modules/, tests/, build/ and developer configuration.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="${1:-$(php -r 'include "control/includes/version.php"; echo $sp_version;')}"
NAME="SubjectsPlus-${VERSION}-stable"
DIST="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "==> Packaging $NAME"

# 1. checks: every PHP file must parse, and the CSS/JS bundles must build
echo "==> php -l on all PHP files ($(php -r 'echo PHP_VERSION;'))"
if ! find . -name '*.php' -not -path './.git/*' -not -path './node_modules/*' -not -path './dist/*' -print0 \
     | xargs -0 -n 50 -P 4 php -l 2>&1 | grep -v '^No syntax errors detected' | grep . ; then
  echo "    all files OK"
else
  echo "!! PHP syntax errors found" >&2; exit 1
fi
echo "==> building / verifying front-end bundles"
php build/build-assets.php

# 2. export the source tree
mkdir -p "$STAGE/$NAME" "$DIST"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "!! note: uncommitted changes are NOT included (git archive packs HEAD)" >&2
  fi
  git archive --format=tar HEAD | tar -x -C "$STAGE/$NAME"
else
  rsync -a --exclude .git ./ "$STAGE/$NAME/"
fi

# 3. remove everything that is not part of a deployable release
cd "$STAGE/$NAME"
rm -rf .git .github node_modules tests build dist \
       .gitignore .gitattributes .gitmodules .editorconfig .snyk .env .env.* \
       package.json package-lock.json phpunit.xml* phpstan.neon* phpstan-baseline.neon psalm.xml* .php-cs-fixer* \
       control/includes/config.php
find assets/cache -type f ! -name '.htaccess' ! -name '.cache' ! -name '.gitkeep' -delete 2>/dev/null || true
find . -name '.DS_Store' -delete
# keep empty runtime folders
for d in assets/cache assets/users assets/records assets/images/guide_thumbs control/lti_controller/temp_files; do
  mkdir -p "$d"; [ -n "$(ls -A "$d" 2>/dev/null)" ] || touch "$d/.gitkeep"
done
cd "$STAGE"

# 4. archives with stable timestamps and ownership
rm -f "$DIST/$NAME.zip" "$DIST/$NAME.tar.gz"
find "$NAME" -exec touch -h -d "@${SOURCE_DATE_EPOCH:-$(git -C "$ROOT" log -1 --format=%ct 2>/dev/null || date +%s)}" {} +
tar --sort=name --owner=0 --group=0 --numeric-owner -czf "$DIST/$NAME.tar.gz" "$NAME"
if command -v zip >/dev/null 2>&1; then
  zip -qrX "$DIST/$NAME.zip" "$NAME"
else
  python3 - "$NAME" "$DIST/$NAME.zip" <<'PY'
import os, sys, zipfile
src, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for base, dirs, files in os.walk(src):
        dirs.sort()
        for f in sorted(files):
            z.write(os.path.join(base, f))
PY
fi

# 5. checksums
cd "$DIST"
sha256sum "$NAME.zip" "$NAME.tar.gz" > SHA256SUMS.txt
echo "==> done"
ls -l "$DIST/$NAME.zip" "$DIST/$NAME.tar.gz"
cat SHA256SUMS.txt
