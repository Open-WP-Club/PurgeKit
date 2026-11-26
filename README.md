# PurgeKit

One control centre for every cache layer on a WordPress site. PurgeKit notices when your site changes, purges your cache plugin, host cache, Varnish and CDN **in the right order (inside to outside)**, then warms the cache again.

Free and open source (GPL-2.0-or-later). No paid tier, no telemetry.

> **Validation:** PHP unit tests, WordPress integration tests in wp-env, and automated React component tests. Configured third-party cache services still need verification against their real infrastructure.

## Requirements

- PHP 8.2 to 8.6
- WordPress 6.6 or newer

## What it does

- **Detects changes**: posts, terms, menus, widgets, Customizer, key settings, theme/plugin switches and updates, approved comments, WooCommerce stock, ACF options, and theme/plugin files changed outside WordPress (FTP, git deploys).
- **Three modes**: notify me, flush automatically, or scheduled flush (hourly to weekly, optionally skipped when nothing changed).
- **Smart purging**: a changed post purges that post, the home page, its terms, author and date archives and the feed. Site-wide changes purge everything.
- **Ordered purging**: object cache, then cache plugin, then host, then Varnish, then CDN. A failing layer is recorded and the run is marked `partial`; it never fails silently.
- **Cache warming** in the background after a purge.
- **History** of every flush with the result of each layer, and who or what started it (user, request, rules, kind of change).
- **First-run setup** and a **Health** tab that checks the saved credentials (Cloudflare token, webhook key, Slack) and the setup.
- **Flush storm detection**: warns, and can pause automatic flushing, when many full flushes happen in a short time.
- **Rule chains**: a rule can wait before it runs (for a CDN that refetches a stale copy right after a purge) or run only when an earlier step failed.
- **Cache tags and soft purge**: pages carry `Surrogate-Key` / `Cache-Tag` headers, a changed post is purged by tag on Fastly and Cloudflare, and Fastly can mark content stale instead of deleting it.
- **Impact** tab, **Run again** / **Retry failed layers** from History, alerts to **Slack, Discord, Teams, email and a JSON webhook**, and **Reload CSS and JavaScript in browsers**.
- **REST API**, **WP-CLI**, a **deploy webhook** and a small **React dashboard** (Settings → PurgeKit).
- **Admin bar** "Purge this page" and a "Purge cache" row action in post lists.

### Supported layers

Object cache, PHP OPcache and APCu (opt-in), database transients (opt-in), WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, WP-Optimize, FlyingPress, Swift Performance, Breeze, Nginx Helper, Nginx FastCGI cache, Varnish, SiteGround, WP Engine, Kinsta, SpinupWP, Pantheon, Cloudflare, Fastly, Bunny CDN, KeyCDN, Sucuri and a custom webhook. Elementor, Divi, Beaver Builder, Autoptimize, Perfmatters and Oxygen (universal CSS) files can be cleared too (opt-in).

## Install

1. Download or build `purgekit.zip` (see [Development](#development)) and upload it in Plugins → Add New.
2. Activate it and open Settings → PurgeKit.
3. For Cloudflare, add the zone ID and an API token (Zone · Cache Purge · Purge). Prefer constants in `wp-config.php`:

```php
define( 'PURGEKIT_CLOUDFLARE_TOKEN', '...' );
```

Keys entered in the UI are stored encrypted (libsodium) and are never shown again.

### Why isn't it cached?

The **Inspector** tab has a second button next to *Inspect*: **Why isn't it cached?** It fetches the page twice as an anonymous visitor and reports whether a cache answered the second time. If none did, it lists what stops caching, worst first: cookies set on an anonymous visit (by name only, never the value), `Cache-Control: private`, `no-store` or `no-cache`, `Vary: Cookie`, error status codes, query strings, and what Cloudflare says (`DYNAMIC`, `BYPASS`).

### Cloudflare extras

With Cloudflare set up, Settings → PurgeKit → Settings has a **Cloudflare tools** panel: purge by URL prefix (`example.com/blog/`) or by cache tag (all plans), switch **development mode** on or off (it ends by itself after three hours), and see whether **Automatic Platform Optimization** is on. Development mode and the APO status need a token that can also read zone settings; the purge works with a purge-only token.

### Many sites

- **Export and import.** Settings → PurgeKit → Settings can download the settings and the saved rules as a JSON file and import it on another site. API tokens, webhook keys and the Slack address are never exported, and neither is the "allow purges from staging" switch.
- **`wp-config.php`.** Fix settings for every site with one array. A fixed setting is shown in the admin but cannot be changed there:

```php
define( 'PURGEKIT_CONFIG', array(
	'mode'               => 'auto',
	'exclude_paths'      => array( '/cart/*', '/checkout/*' ),
	'warm_sitemap_limit' => 10,
) );
```

The names are the ones in the exported file. Secrets keep their own constants (`PURGEKIT_CLOUDFLARE_TOKEN`, and so on).

### Editor

The block editor's document sidebar has a **PurgeKit cache** panel. Press *Check caches* to see which layers serve the page to visitors, and purge one layer or the whole page from there. Nothing is requested until you press the button.

## Usage

### WP-CLI

```bash
wp purgekit flush                 # everything
wp purgekit flush https://example.com/page/
wp purgekit flush --post=123
wp purgekit flush --dry-run       # show what would be purged, purge nothing
wp purgekit status
wp purgekit layers
wp purgekit scan                  # look for file changes made outside WordPress
wp purgekit warm
```

`flush` exits non-zero when a layer failed.

### Deploy webhook

Create a key in Settings → PurgeKit, then:

```bash
curl -X POST https://example.com/wp-json/purgekit/v1/webhook/flush \
  -H "X-PurgeKit-Key: <key>" \
  -H "Content-Type: application/json" \
  -d '{"urls":["https://example.com/page/"]}'   # body optional; omit to purge everything
```

### Developer hooks

| Hook | Type | Purpose |
|---|---|---|
| `purgekit_layers` | filter | Add or remove cache layers (implement `PurgeKit\Layers\LayerInterface`) |
| `purgekit_post_urls` | filter | URLs purged when a post changes |
| `purgekit_preload_urls` | filter | URLs warmed after a full flush |
| `purgekit_watched_options` | filter | Options that count as a site-wide change |
| `purgekit_ignored_post_types` | filter | Post types to ignore |
| `purgekit_fingerprint_dirs` / `purgekit_fingerprint_extensions` | filter | What the file scan looks at |
| `purgekit_capability` | filter | Who can manage PurgeKit (default `manage_options`) |
| `purgekit_history_days` | filter | History retention (default 90) |

## Architecture

REST-first. `src/Core` is plain PHP with no WordPress calls (Change → Queue → Planner → Executor → Flusher) behind small interfaces (`Store`, `Http`, `Lock`, `Clock`), so it is unit-tested with fakes. WordPress-specific code lives in adapters (`src/Wp`, `src/Detect`, `src/Rest`, `src/Cli`, `src/Admin`). The React app in `assets/src` only talks to `/wp-json/purgekit/v1`.

## Change batching, dependencies and warming

Automatic mode collects changes for **20 quiet seconds**, with a **300-second maximum** from the first queued change. Both values are configurable in Settings → General (`batch_delay`: 0–120; `batch_maximum`: 30–3600, never less than the quiet window). Set the quiet time to 0 for immediate flushing. WordPress cron runs background work when it gets an opportunity; the maximum bounds the collection window, not cron delivery time. Manual purges stay immediate.

Imports using WordPress `import_start` / `import_end` are grouped until they finish or reach the maximum wait. Custom importers can use `do_action( 'purgekit_batch_begin' )` and `do_action( 'purgekit_batch_end' )`. An unfinished import hold expires at the maximum wait.

Before editing or deleting published content, PurgeKit captures its old URLs and tags. Old and new category, author, date and permalink dependencies are invalidated together. Direct taxonomy assignments and removals are detected too.

Shared content dependencies include nested synced blocks, template parts, Query Loops by post type, Elementor `template_id` data and `[elementor-template id="…"]` shortcodes, and Beaver Builder `node_template_id` data. Global theme templates, Divi library layouts and Elementor templates with display conditions use a full purge. The dependency scan is bounded to 500 candidate records; overflow also uses a full purge. This is conservative dependency detection, not a complete parser for every builder's display rules.

Preview and History explain which change selected each URL, including old dependencies and matching rules. History also keeps the selected layers and rule delays. Reasons are limited to 50 targets and ten causes per target in the interface and stored history; old history remains readable.

Warming retries network failures, HTTP 408/429 and server errors up to **three times**, after 15, 30 and 60 seconds. HTTP 429/503 pauses the queue and honours `Retry-After` (up to one hour). Permanent errors are counted as failed. Settings → General accepts up to 50 priority warming URLs: they go first when affected, and full purges include them. Requests stay on this site's origin and do not follow redirects. Dashboard shows pending, successful and failed requests plus retry counts for the current cycle. `wp purgekit warm` processes ready work and schedules delayed retries in the background.

## Comparing cache variants

Inspector → **Compare cache variants** compares up to four anonymous URL/device/language combinations. Choose desktop or mobile, optionally set an `Accept-Language` code, and enter translated paths or URLs with query parameters. Each variant gets its own cached-versus-cache-busted content check and cacheability report. Cookies are never supplied, and response cookie values are never included in reports. A cache-busted response is a comparison sample, not proof that every upstream cache was bypassed; dynamic content can also differ.

The authenticated `POST /wp-json/purgekit/v1/inspect/variants` route accepts:

```json
{"variants":[{"url":"https://example.com/bg/?currency=BGN","device":"mobile","language":"bg-BG"},{"url":"https://example.com/en/?currency=EUR","device":"desktop","language":"en"}]}
```


## Development

```bash
composer install
pnpm install

composer test        # PHPUnit unit tests
composer lint        # PHPCS (WordPress standard)
composer analyse     # PHPStan
pnpm test:js        # Jest
pnpm build          # React build into build/

pnpm exec wp-env start # needs Docker
pnpm test:php-env   # integration tests inside wp-env
./bin/build-zip.sh   # builds purgekit.zip (production dependencies only)
```

### Releasing

Push a plain semver tag. The `Build & Release` workflow runs the checks, stamps the tag into the plugin header, `PURGEKIT_VERSION` and `Stable tag`, builds `purgekit-<tag>.zip` and publishes a GitHub release with the commit list since the previous tag.

```bash
git tag 0.2.0 && git push origin 0.2.0
```

> **Warning:** `wp-env` bind-mounts this repository into the container as `plugins/PurgeKit`. Never run `rm -rf` on that path (or a differently-cased name of it) inside the container, it deletes the working tree on the host.

CI runs on PHP 8.2 to 8.6 plus the JS build and tests.

## License

GPL-2.0-or-later.
