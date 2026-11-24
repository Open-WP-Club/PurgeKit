# TODO

Ideas for later, roughly by value for the effort. Nothing here is started.

## Diagnostics

- **"Why isn't it cached?"** A companion to the "Why isn't my change showing?" inspector. Explain what stops a page from being cached: `Set-Cookie` on an anonymous request, `Cache-Control: private` or `no-store`, `Vary: Cookie`, cookies set by plugins. The data is already in the headers the inspector collects (`src/Inspector`).
- **Cache monitor.** Check a few key URLs on a schedule and report to Site Health or Slack when the CDN keeps answering `BYPASS` or `MISS`. Reuses the inspector and `Alerts\Notifier`.
- **Credentials health check.** A Health tab that checks the saved credentials (Cloudflare token scope, weak or never-rotated webhook key, unreachable Slack URL). Extends `LayerInterface::test()`.

## Purging

- **More Cloudflare actions.** Purge by prefix or tag (only on plans that support it), development mode (temporarily bypasses the cache), Cloudflare APO.
- **More CDNs.** CloudFront (needs AWS SigV4 request signing), Akamai, Azure CDN, Google Cloud CDN. Needs real accounts to verify, so do these last.
- **Cache-tag purging** (Fastly surrogate keys first): send `Cache-Tag` / `Surrogate-Key` headers and purge by tag instead of by URL. Cloudflare only supports tags on Enterprise, so it would fall back to URLs there.
- **Soft purge for Fastly.** Mark content stale instead of deleting it, so a purge does not send a burst of requests to the origin. A header in `ProviderSpecs`.
- **Asset version bump.** A button that changes the version query string of CSS and JS, to push browsers to reload them. Browser caches cannot be purged remotely, but this works around it.
- **REST, feed and AMP URLs** added to the URLs of a changed post (`wp-json/wp/v2/...`, comment feed, `?amp`), for headless and mobile clients. All behind the existing `purgekit_post_urls` filter.
- **More cache plugins and hosts.** WP-Optimize, FlyingPress, Cache Enabler, Hummingbird, Swift, Comet, Pantheon, GoDaddy, Pagely, SpinupWP, Pressable/VIP, Cloudways. The plugin ones can be checked against their source; the hosts need access to verify.
- **Page builders still missing:** Bricks, Oxygen.

## Rules and history

- **Rule chains.** Conditions such as "only if the other layer failed" and "wait N seconds before the CDN", for CDNs that sometimes refetch a stale copy from the origin right after a purge.
- **Who or what triggered a flush.** Record the user, the request (webhook, WP-CLI, admin) and the matching rule with each history row.
- **Flush storm detection.** Warn, and optionally fall back to notify mode, when many full flushes happen in a short time (a bad cron or a plugin flushing in a loop).

## Admin experience

- **Export and import settings**, and configuration through constants in `wp-config.php`, for agencies that manage many sites.
- **Editor sidebar.** In the block editor, show which cache layers hold the page being edited, with a purge button. Built on the inspector.
- **First-run setup.** A short first screen that explains the modes and shows what was detected.

## Not verified yet (needs a real WordPress)

- The integration tests (`bin/test-local.sh full`, needs Docker), including `UninstallerTest::test_uninstall_removes_table_options_and_cron`, which failed once in CI and was never investigated, and the multisite run.
- The admin screen in a browser. The stylesheet was written without seeing it.
- Real accounts: Cloudflare, Slack, Fastly, Bunny, KeyCDN, Sucuri (the API host differs between sources: `waf.` or `wafx.sucuri.net`), WP Engine and Kinsta (function names are community-documented only), the Nginx cache directory.
- Elementor's `files_manager->clear_cache()` and Divi's `ET_Core_PageResource::remove_static_resources()`; they are guarded and fail loudly if the method is missing.
- WPML and Polylang URL expansion, and the WooCommerce shop, upsell and variation URLs.
- The `Contributors` names in `readme.txt` are GitHub logins, not confirmed WordPress.org usernames.
