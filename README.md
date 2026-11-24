# PurgeKit

One control centre for every cache layer on a WordPress site. PurgeKit notices when your site changes, purges your cache plugin, host cache, Varnish and CDN **in the right order (inside to outside)**, then warms the cache again.

Free and open source (GPL-2.0-or-later). No paid tier, no telemetry.

> **Status:** v0.1 (core). Written and unit-tested; the integration tests and the admin UI still need a run on a real WordPress.

## Requirements

- PHP 8.2 to 8.6
- WordPress 6.4 or newer

## What it does

- **Detects changes**: posts, terms, menus, widgets, Customizer, key settings, theme/plugin switches and updates, approved comments, WooCommerce stock, ACF options, and theme/plugin files changed outside WordPress (FTP, git deploys).
- **Three modes**: notify me, flush automatically, or scheduled flush (hourly to weekly, optionally skipped when nothing changed).
- **Smart purging**: a changed post purges that post, the home page, its terms, author and date archives and the feed. Site-wide changes purge everything.
- **Ordered purging**: object cache, then cache plugin, then host, then Varnish, then CDN. A failing layer is recorded and the run is marked `partial`; it never fails silently.
- **Cache warming** in the background after a purge.
- **History** of every flush with the result of each layer.
- **REST API**, **WP-CLI**, a **deploy webhook** and a small **React dashboard** (Settings → PurgeKit).
- **Admin bar** "Purge this page" and a "Purge cache" row action in post lists.

### Supported layers (v0.1)

Object cache, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Nginx Helper, SiteGround, WP Engine, Kinsta, Varnish and Cloudflare.

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
