#!/usr/bin/env bash
# Heavy checks run here, not in CI. Usage: bin/test-local.sh [quick|full]
#   quick (default): unit, lint, static analysis, JS tests
#   full:            quick + wp-env integration tests (single site and multisite); needs Docker
set -euo pipefail
cd "$(dirname "$0")/.."
composer lint
composer analyse
composer test
pnpm run test:js
if [ "${1:-quick}" = "full" ]; then
	plugin_dir="$(basename "$PWD")"
	npx wp-env start
	echo "== integration: single site =="
	pnpm run test:php-env
	echo "== integration: multisite =="
	npx wp-env run tests-cli --env-cwd="wp-content/plugins/$plugin_dir" env PURGEKIT_MULTISITE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist
fi
