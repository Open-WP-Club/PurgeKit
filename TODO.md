# TODO

Ideas for later, roughly by value for the effort. Nothing here is started.

## Purging

- **Cloudflare APO switching.** The APO status is shown read-only. Turning it on or off needs the whole APO configuration, so it stays in the Cloudflare dashboard for now.
- **More CDNs.** CloudFront (needs AWS SigV4 request signing), Akamai, Azure CDN, Google Cloud CDN. Needs real accounts to verify, so do these last.
- **More cache plugins and hosts.** Still open, and why: Hummingbird (the `wphb_clear_page_cache` action exists, but what it does without an argument was not read), Comet Cache (not looked at), GoDaddy (no public source for the purge code), Pagely, Pressable and WordPress VIP (Batcache and the VIP edge functions were not checked), Cloudways beyond Breeze. Added so far: Cache Enabler, WP-Optimize, FlyingPress, Swift Performance, Breeze, SpinupWP, Pantheon.
- **Page builders still missing:** Bricks. It has no documented PHP call for clearing its CSS (only the `bricks/generate_css_file` action and `wp bricks regenerate_assets`). Oxygen is covered only for its universal CSS, with a function known from a third-party snippet (Oxygen 3.9 and 4.0); per-post CSS and Oxygen 6 are not verified.
- **Cache tags beyond Fastly and Cloudflare.** Bunny and KeyCDN have no tag purge that was checked.
- **Playwright end-to-end test and a working ESLint.** Both need a dependency change: `@playwright/test` is only a transitive dependency of `@wordpress/scripts` (not importable from the project), and `wp-scripts lint-js` fails because the installed TypeScript 7 does not work with `typescript-eslint` (`pnpm add -D typescript@5` should fix it). Not added on purpose: neither documents a function that clears its generated CSS, and deleting the files in `uploads/` by hand is not known to be safe (Elementor never recreates a deleted file). Read the plugin source for the function behind each "regenerate CSS" button first.

## Rules and history


## Admin experience

