=== PurgeKit ===
Contributors: mrgkanev, openwpclub
Tags: cache, purge cache, clear cache, cloudflare, varnish
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One control centre for every cache layer on your site. Detects changes, purges your cache plugin, host, CDN and Varnish in the right order, then warms the cache.

== Description ==

Most WordPress sites stack several caches: a cache plugin, the host's server cache, a CDN and the object cache. When you change something, you often don't know which one is still serving the old version.

PurgeKit notices when your site changes, then clears every cache layer it finds, from the inside out, and warms the cache again. It works alongside your existing cache plugin. It does not cache anything itself. It is free and has no paid tier.

**What it does**

* Detects changes to posts, terms, menus, widgets, Customizer, key settings, themes, plugins, comments, WooCommerce stock, ACF options and theme or plugin files changed outside WordPress (FTP, git deploys).
* Three modes: notify me, flush automatically, or scheduled flush (every hour up to weekly, optionally skipping when nothing changed).
* Purges only the pages that changed: the post, home page, its categories and tags, author and date archives and the feed. Menus, theme and settings changes purge everything.
* Purges layers inside to outside: object cache, cache plugin, host cache, Varnish, then CDN.
* Warms the cache in the background after a purge.
* History of every flush with the result of each layer.
* WP-CLI: `wp purgekit flush|status|layers|scan|warm`.
* Deploy webhook with a secret key, for GitHub Actions or any deploy script.
* "Purge this page" in the admin bar and in post lists.

**Supported layers**

Object cache, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Nginx Helper, SiteGround, WP Engine, Kinsta, Varnish and Cloudflare.

**Developers**

* `purgekit_layers` (filter): add or remove cache layers. Implement `PurgeKit\Layers\LayerInterface`.
* `purgekit_post_urls` (filter): URLs purged when a post changes.
* `purgekit_preload_urls` (filter): URLs warmed after a full flush.
* `purgekit_watched_options` (filter): settings that count as a site-wide change.
* `purgekit_ignored_post_types` (filter): post types to ignore.
* `purgekit_capability` (filter): who can manage PurgeKit (default `manage_options`).
* API keys can be set as constants in wp-config.php, for example `define( 'PURGEKIT_CLOUDFLARE_TOKEN', '...' );`.

== Installation ==

1. Upload the `purgekit` folder to `/wp-content/plugins/`, or install from Plugins > Add New.
2. Activate the plugin.
3. Go to Settings > PurgeKit. Detected layers are listed on the Dashboard tab.
4. Choose how PurgeKit should react to changes. For Cloudflare, add your zone ID and API token on the Settings tab.

== Frequently Asked Questions ==

= Is this a caching plugin? =

No. PurgeKit works with the cache plugin, host and CDN you already use.

= What permissions does the Cloudflare token need? =

Zone, Cache Purge, Purge. Restrict the token to the zone for this site.

= Are my API keys safe? =

Keys set as constants in wp-config.php are never stored. Keys entered in the settings are stored encrypted and are never shown again.

== External services ==

PurgeKit sends no analytics or telemetry. It only contacts services you configure.

= Cloudflare =

When you configure Cloudflare, PurgeKit sends your API token and either a "purge everything" request or a list of page URLs to the Cloudflare API (api.cloudflare.com) every time it flushes.

* [Cloudflare Terms of Use](https://www.cloudflare.com/website-terms/)
* [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)

Varnish purge requests and cache warming only go to your own site and server.

== Changelog ==

= 0.1.2 =
* New: layer map with purge order, last result per layer and a per-layer purge button.
* New: saved flush rules (when X changes, do Y on these layers), with a preview of what the next flush would do.
* New: after a purge, optionally check that the home page and purged pages no longer serve a stale copy.
* New: warm the cache from the site sitemap (WordPress core, Yoast, Rank Math, All in One SEO, SEOPress).
* New: scheduled one-off purges, paths that are never purged, and switching a layer off without losing its settings.
* New: CDN purges pause on staging sites and on copies of the site with a new address.
* New: Site Health test and an admin notice when a purge fails.
* New: Fastly, Bunny CDN, KeyCDN, Sucuri and Nginx FastCGI cache layers, Slack and email alerts, CSV export of the history, multisite support.
* Improved: a changed post also purges its custom post type archive, the blog page, parent pages and categories, the first listing pages, translations (Polylang, WPML) and, for WooCommerce, the shop page, upsells, cross-sells and the product behind a variation.
* Fixed: two different posts changed before a flush were treated as one, so the second was never purged.
* Fixed: with saved rules, changes that affect the whole site were not purged.

= 0.1.0 =
* First release.
