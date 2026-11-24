# TODO

Ideas for later, roughly by value for the effort. Nothing here is started.

## Diagnostics

- **Credentials health check.** A Health tab that checks the saved credentials (Cloudflare token scope, weak or never-rotated webhook key, unreachable Slack URL). Extends `LayerInterface::test()`.

## Purging

- **Cloudflare APO switching.** The APO status is shown read-only. Turning it on or off needs the whole APO configuration, so it stays in the Cloudflare dashboard for now.
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

- **First-run setup.** A short first screen that explains the modes and shows what was detected.

