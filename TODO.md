# TODO / handover

Last updated: 2026-11-24. Branch: `main`. Version: 0.1.0 (core).

## Where we are

v0.1 is implemented and merged: change detection, queue, planner, ordered executor, flusher with lock, history table, settings and modes (notify/auto/scheduled), warmer, 12 layers, REST API, webhook, WP-CLI, React dashboard (Dashboard / History / Settings), admin bar and row actions, uninstaller, build script, CI.

Verified before the PHP floor change: 71 PHP unit tests, 57 integration tests (`wp-env`), 7 Jest tests, PHPCS and PHPStan clean, CI green on PHP 8.0 and 8.3.

## Done on branch v0.1.1-hardening

- CI confirmed green on PHP 8.2 to 8.6 (the PHP floor change is verified).
- Cloudflare `test()` now does a read-only `GET /zones/{id}` (token redacted from errors); closes the API half of item 5 below. The UI "Test connection" button is still missing.
- `Cron::sync()` also runs when the `mode` or `schedule` option is added or updated, so item 7 is fixed.

## Previously changed (verified, kept for history)

- Minimum PHP raised to **8.2**; supported range is **8.2 to 8.6**. `composer.json` (`require` + `platform` 8.2.30), plugin header, `readme.txt`, CI matrix (8.2 to 8.6) and docs updated. Unit tests, PHPCS and PHPStan pass locally on PHP 8.6.
- Check that CI can install PHP 8.5/8.6 with `shivammathur/setup-php`. If a version is unavailable, drop it from the matrix and note it here.
- `composer.lock` was re-resolved on the 8.2 platform. If CI fails on `composer install`, run `composer update` locally and commit.

## Known problems / unverified

1. **Vendor function names are unverified.** `src/Layers/Catalog.php` calls Kinsta, WP Engine, SiteGround, W3TC, WP Fastest Cache, Nginx Helper and LiteSpeed APIs from memory. A wrong name makes that layer record `failed` (the executor catches `Throwable`), it does not crash the site. Verify each against the vendor's current docs before a release.
2. **Built zip was never smoke-tested in WordPress.** `bin/build-zip.sh` builds a good zip, but the install, activate, `wp purgekit status|flush` check was interrupted (see incident below). Redo it by copying the zip to a path outside the repo.
3. **No final whole-branch code review** was run (the plan calls for one after the last task).
4. **React UI never viewed in a browser.** Only Jest tests exist. Check layout, the tab panel and the Settings form manually (`npx wp-env start`, http://localhost:8888/wp-admin, Settings → PurgeKit).
5. **Cloudflare**: zone ID must be typed in; no zone lookup from the domain, no "Test connection" button in the UI yet (REST `POST /layers/{id}/test` works and now calls the API).
6. **Webhook key** is shown only once after create/rotate; the UI does not display the full webhook URL.
7. ~~`Cron::sync()` only on admin_init~~ fixed: also hooked to mode/schedule option changes.
8. **Multisite** is not implemented (`Uninstaller` loops over sites but is untested). Planned for v0.3.
9. `readme.txt` still has placeholders: `Contributors: purgekit`, `Tested up to: 6.8`.
10. Warming is sequential (5 URLs per batch), not parallel.
11. History stores the full URL list as JSON; very large purges make large rows.
12. Early commits of the v0.1 work carry a different author email than the configured git identity. Decide whether that matters before the repo is widely shared; fixing it means rewriting history.

## Incident (read before using wp-env)

During the zip smoke test a command ran `rm -rf purgekit` inside the `wp-env` cli container. The repo is bind-mounted there as `plugins/PurgeKit` and the host filesystem is case-insensitive, so the whole working tree was deleted. Everything was recovered (remote history, an auto-commit, and recreated tests, React sources and docs). Never run `rm` on that mount. The recreated test, React and doc files were re-typed from the session, so if something looks off, compare against CI results.

Also: a local hook auto-commits with the message `1` in repositories that have no first commit. Be careful with `git checkout -B` right after `git init`.

## Next (roadmap)

- **v0.2 See**: cache layer map screen (each layer in tier order, detected / configured / last result, "Test connection"), ordered-purge UX, **"Why isn't my change showing?" inspector** (fetch the URL as visitor and with a cache-buster, read cache headers, report which layer serves the stale copy, "purge this layer" button; header knowledge lives in each layer via `fingerprintHeaders()`).
- **v0.3 Control**: saved flush rules (JSON: trigger, target, layers), Slack (incoming webhook) and email alerts, history UI with filters and CSV export, multisite (network defaults, per-site override, purge all sites).
- **v0.4 Reach**: Fastly, CloudFront, Sucuri, Nginx FastCGI, Cloudflare APO, Surge; remaining cache plugins and hosts (WP-Optimize, FlyingPress, Cache Enabler, Hummingbird, Swift, Comet, Autoptimize, Pantheon, GoDaddy, Pagely, SpinupWP, Pressable/VIP, Bunny, Cloudways), page-builder CSS; WordPress.org assets and readme.

## Conventions to keep

- `src/Core` must not call WordPress functions; use the interfaces.
- Tests first; every layer gets a unit test against fake HTTP.
- PHPUnit stays on 9.6 (the WordPress test suite does not support 10+).
- Secrets: constants first, then encrypted option; never returned by REST.
- Add files with explicit paths, never `git add -A`.
- A reference folder `fcb-cache-watch/` may exist locally (untracked, excluded via `.git/info/exclude`). It is only for reading behaviour; do not commit it or mention it in the plugin.
