# PurgeKit — design

Date: 2026-11-24 · Status: approved

## Intent and why

A free WordPress plugin (GPL-2.0-or-later, WordPress.org-ready) that is the single control centre for every cache layer on a site: it notices when something changed, then purges the cache plugin, host, CDN and object cache in the right order, then warms the cache. Used by the author's clients and anyone who installs it. **Everything is free. No plans, no paywalled features.**

Built from scratch with a focus on being productive, easy to test and easy to extend.

## Goals

- **Testable by construction**: core logic has no WordPress dependency and is unit-tested; every driver is tested against fake HTTP responses.
- **REST-first**: every feature is a REST endpoint; the React admin, WP-CLI and the deploy webhook are thin clients of the same service layer.
- **Small units**: one responsibility per class, no file over ~300 lines.
- **Safe secrets**: API keys come from `wp-config.php` constants first; otherwise stored encrypted (libsodium, key derived from WP salts). Never printed back to the UI.
- **Multisite aware** (network activation, per-site purge, network-wide purge).
- Cover the full feature set below.

## Non-goals / anti-goals

- Not a caching plugin. It never stores page cache itself.
- No telemetry, no analytics, no phone-home. External requests only to services the user configured.
- No paid tier, no upsell UI, no licence checks.
- Anti-goal: a purge that "succeeds" while a layer silently failed. Failures must be loud and in the history.

## Constraints

- PHP 8.2 to 8.6 (supported range), WordPress 6.4+.
- Composer PSR-4 autoload (`PurgeKit\`); the built plugin zip ships `vendor/autoload.php` with no dev dependencies.
- Admin UI: `@wordpress/scripts` build, `@wordpress/components`, talks only to `/wp-json/purgekit/v1`.
- Plugin slug / text domain / prefix: `purgekit`.
- Capability `manage_options` (filterable).

## Approaches considered

| | Approach | Verdict |
|---|---|---|
| **A** | REST-first PHP core + React admin (`@wordpress/scripts`) | **Chosen by author.** Best testability; map/inspector/rules need rich live UI. Costs: Node build step, no PHP 7.4. |
| B | Same core, server-rendered PHP admin + vanilla JS | Rejected: ~30% less code but weak fit for the live layer map and inspector. |

## Architecture

```
purgekit/
  purgekit.php              bootstrap only
  src/
    Core/                   pure PHP, no WP calls
      Change, Queue, Planner, Executor, Flusher, LayerResult, Run, PurgePlan
      interfaces: Store, Http, Lock, Clock, UrlExpander, HistoryWriter
    Layers/                 one class per cache layer, implements LayerInterface
      LayerInterface: id(), label(), tier(), detect(), purgeUrls(), purgeAll(), test()
      Registry (discovery + filter `purgekit_layers`), Catalog, Tier
    Detect/                 WP hooks → Change, FileScanner, PostUrlExpander
    Inspector/              URL inspector (v0.2)
    Rules/                  saved flush rules (v0.3)
    Notify/                 Email, Slack (v0.3)
    History/                custom table repository, retention pruning
    Warm/                   background warming
    Secrets/                constant → encrypted option resolver
    Rest/                   controllers (one per resource)
    Cli/                    WP-CLI commands
    Admin/                  menu, admin bar, notices
  assets/src/               React app
  tests/Unit  tests/Integration
```

Dependency rule: `Core` imports nothing from WordPress. Everything WP-specific is behind an interface (Http, Clock, Store, Lock) so Core and Layers are unit-testable with fakes.

## Flow

1. **Detect** — a WP hook or the file scanner creates a `Change`.
2. **Queue** — changes are deduped; any site-scope change escalates the whole batch to "everything".
3. **Mode** (setting): *notify* (admin notice, admin-bar dot, optional email), *auto* (flush on shutdown), or *scheduled* (every 1/3/6/12h, daily, weekly; optionally skip if nothing changed).
4. **Plan** — `Planner` applies smart-purge defaults (post URL, home, its terms, author, date archives, feed) or full purge; drops other hosts and fragments.
5. **Execute** — layers run **inside → outside** by `tier()`: object cache → cache plugin → host/server cache → reverse proxy (Varnish) → CDN. A failed layer is recorded; the run continues and its status is `partial`.
6. **Record** — one history row per run with per-layer results.
7. **Notify** — Slack/email per user config (v0.3).
8. **Warm** — affected URLs are fetched in the background, 5 at a time.

## Features

### Core (all free)
Change detection (content, settings, files); notify / auto / scheduled; targeted purging; scheduled flush and warming; WP-CLI (`wp purgekit flush|status|layers|scan|warm`); deploy webhook with secret key; developer filters and actions (`purgekit_*`); admin-bar "Purge this page"; post-list purge row action.

### Advanced
1. **Cache layer map** — one screen showing every layer in tier order: detected, configured, last purge result, "Test connection".
2. **Ordered purging** — see Flow step 5.
3. **"Why isn't my change showing?" inspector** — user enters a URL. The inspector requests it as a visitor and with a cache-busting variant, reads cache headers, compares, and reports which layer is serving the stale copy plus a "purge this layer" button. Header→layer knowledge lives in each layer class.
4. **Email and Slack alerts** — Slack via incoming-webhook URL, no SDK. Sent only when configured. `NotifierInterface` for later channels.
5. **Full change and flush history** — custom table, filterable, retention default 90 days, CSV export.
6. **Saved page flush rules** — named JSON rules: trigger → target (URL patterns, groups, or everything) → layers.
7. **Secrets** — constants win; else encrypted at rest; write-only in UI.
8. **Multisite** — network admin defaults, per-site override, "purge all sites".

## Layers

**v0.1:** WP object cache, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Nginx Helper, SiteGround, WP Engine, Kinsta, Varnish (HTTP PURGE), Cloudflare.
**v0.4+:** Fastly, CloudFront, Sucuri, Nginx FastCGI cache, Cloudflare APO, Surge; more cache plugins and hosts, page-builder CSS.

Extending: implement `LayerInterface`, register via `purgekit_layers` filter.

## Testing strategy

- **Unit** (PHPUnit + Brain Monkey, no DB): Queue, Planner, Executor ordering and failure handling, Secrets, each Layer against a fake `Http`.
- **Integration** (`@wordpress/env`): detectors on real WP actions, REST permissions, history table, uninstall.
- **JS** (Jest via `@wordpress/scripts`): React components against mocked REST.
- CI: GitHub Actions running PHPCS (WordPress standard), PHPStan, PHPUnit, JS lint/test, plugin build.

## Milestones

- **v0.1 Core**: Core + Detect + Queue/Planner/Executor + History + 12 layers + REST + WP-CLI + webhook + minimal React dashboard + tests + CI.
- **v0.2 See**: layer map, ordered purge UX, inspector.
- **v0.3 Control**: saved rules, Slack/email alerts, history UI + export, multisite.
- **v0.4 Reach**: new layers, remaining layers, WordPress.org readme and assets.

## Left to the builder

Table schema details, exact REST route shapes, React component structure, file-scanner defaults, warming concurrency tuning, PHPCS ruleset specifics.

## Calls made by the builder (author may veto)

- PHP 8.2+ / WP 6.4+ minimum (raised from 8.0 by the author).
- Name, slug and prefix `purgekit`.
- Secrets: constants first, then sodium-encrypted option.
- History in a custom table with 90-day retention.
- Slack via webhook only; email via `wp_mail`.
- A failed layer does not stop outer layers; the run is `partial` and recorded.
