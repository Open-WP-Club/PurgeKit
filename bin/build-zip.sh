#!/usr/bin/env bash
# Builds purgekit.zip with production dependencies only.
set -euo pipefail
cd "$(dirname "$0")/.."
npm run build
stage="$(mktemp -d)/purgekit"
mkdir -p "$stage"
cp -R purgekit.php uninstall.php readme.txt composer.json composer.lock src build "$stage/"
(cd "$stage" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
rm -f "$stage/composer.json" "$stage/composer.lock"
rm -f purgekit.zip
(cd "$(dirname "$stage")" && zip -qr "$OLDPWD/purgekit.zip" purgekit)
echo "Built purgekit.zip"
