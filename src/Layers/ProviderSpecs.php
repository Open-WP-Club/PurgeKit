<?php
namespace PurgeKit\Layers;

/**
 * Declarative descriptions of HTTP-purgeable providers. Adding a provider means adding an entry here,
 * plus a test against the fake HTTP client; no new class.
 *
 * `fields` drives both the settings form and the REST whitelist: only keys listed here can be written.
 */
final class ProviderSpecs {
	/** @return array<string, array<string, mixed>> */
	public static function all(): array {
		return array(
			// Fastly purging API: https://www.fastly.com/documentation/reference/api/purging/
			// Checked against the vendor docs on 2026-11-24.
			'fastly'  => array(
				'id'          => 'fastly',
				'label'       => 'Fastly',
				'tier'        => Tier::CDN,
				'secrets'     => array( 'FASTLY_TOKEN' ),
				'settings'    => array( 'fastly_service_id' ),
				'fields'      => array(
					array(
						'key'   => 'fastly_service_id',
						'kind'  => 'setting',
						'label' => 'Fastly service ID',
					),
					array(
						'key'   => 'FASTLY_TOKEN',
						'kind'  => 'secret',
						'label' => 'Fastly API token (purge scope)',
					),
				),
				'all'         => array(
					'method'  => 'POST',
					'url'     => 'https://api.fastly.com/service/{fastly_service_id}/purge_all',
					'headers' => array( 'Fastly-Key' => '{FASTLY_TOKEN}' ),
				),
				'url'         => array(
					'method'  => 'POST',
					'url'     => 'https://api.fastly.com/purge/{url_noscheme}',
					'headers' => array( 'Fastly-Key' => '{FASTLY_TOKEN}' ),
				),
				'test'        => array(
					'method'  => 'GET',
					'url'     => 'https://api.fastly.com/service/{fastly_service_id}',
					'headers' => array( 'Fastly-Key' => '{FASTLY_TOKEN}' ),
				),
				'fingerprint' => array(
					'header'   => 'x-cache',
					'pattern'  => '/HIT/i',
					'requires' => array( 'x-served-by', '/^cache-/i' ),
				),
			),
			// Bunny docs: https://docs.bunny.net/api-reference/core/pull-zone/purge-cache and .../core/purge/purge-url
			// Checked against the vendor docs on 2026-11-24.
			'bunny'   => array(
				'id'          => 'bunny',
				'label'       => 'Bunny CDN',
				'tier'        => Tier::CDN,
				'secrets'     => array( 'BUNNY_ACCESS_KEY' ),
				'settings'    => array( 'bunny_pullzone_id' ),
				'fields'      => array(
					array(
						'key'   => 'bunny_pullzone_id',
						'kind'  => 'setting',
						'label' => 'Bunny pull zone ID',
					),
					array(
						'key'   => 'BUNNY_ACCESS_KEY',
						'kind'  => 'secret',
						'label' => 'Bunny account API key',
					),
				),
				'all'         => array(
					'method'  => 'POST',
					'url'     => 'https://api.bunny.net/pullzone/{bunny_pullzone_id}/purgeCache',
					'headers' => array( 'AccessKey' => '{BUNNY_ACCESS_KEY}' ),
				),
				'url'         => array(
					'method'  => 'POST',
					// exactPath: a URL ending in "/" would otherwise purge everything below it (the home page would purge the whole site).
					'url'     => 'https://api.bunny.net/purge?url={url_encoded}&async=false&exactPath=true',
					'headers' => array( 'AccessKey' => '{BUNNY_ACCESS_KEY}' ),
				),
				'test'        => array(
					'method'  => 'GET',
					'url'     => 'https://api.bunny.net/pullzone/{bunny_pullzone_id}',
					'headers' => array( 'AccessKey' => '{BUNNY_ACCESS_KEY}' ),
				),
				'fingerprint' => array(
					'header'  => 'cdn-cache',
					'pattern' => '/^HIT/i',
				),
			),
			// KeyCDN API: https://www.keycdn.com/api (purge is limited to 1 request per second, so URLs go in batches).
			// Checked against the vendor docs on 2026-11-24.
			'keycdn'  => array(
				'id'          => 'keycdn',
				'label'       => 'KeyCDN',
				'tier'        => Tier::CDN,
				'secrets'     => array( 'KEYCDN_API_KEY' ),
				'settings'    => array( 'keycdn_zone_id' ),
				'fields'      => array(
					array(
						'key'   => 'keycdn_zone_id',
						'kind'  => 'setting',
						'label' => 'KeyCDN zone ID',
					),
					array(
						'key'   => 'KEYCDN_API_KEY',
						'kind'  => 'secret',
						'label' => 'KeyCDN API key',
					),
				),
				'all'         => array(
					'method'  => 'GET',
					'url'     => 'https://api.keycdn.com/zones/purge/{keycdn_zone_id}.json',
					'headers' => array( 'Authorization' => 'Basic {basic:KEYCDN_API_KEY}' ),
				),
				'url'         => array(
					'method'  => 'DELETE',
					'url'     => 'https://api.keycdn.com/zones/purgeurl/{keycdn_zone_id}.json',
					'headers' => array( 'Authorization' => 'Basic {basic:KEYCDN_API_KEY}' ),
					'body'    => array( 'urls' => '{urls_noscheme}' ),
					'batch'   => 50,
				),
				'test'        => array(
					'method'  => 'GET',
					'url'     => 'https://api.keycdn.com/zones/{keycdn_zone_id}.json',
					'headers' => array( 'Authorization' => 'Basic {basic:KEYCDN_API_KEY}' ),
				),
				'fingerprint' => array(
					'header'   => 'x-cache',
					'pattern'  => '/HIT/i',
					'requires' => array( 'server', '/keycdn/i' ),
				),
			),
			// Sucuri documents only a whole-cache clear; the API host differs between sources (waf / wafx), so confirm on a real account.
			'sucuri'  => array(
				'id'          => 'sucuri',
				'label'       => 'Sucuri Firewall',
				'tier'        => Tier::CDN,
				'secrets'     => array( 'SUCURI_API_KEY', 'SUCURI_API_SECRET' ),
				'settings'    => array(),
				'fields'      => array(
					array(
						'key'   => 'SUCURI_API_KEY',
						'kind'  => 'secret',
						'label' => 'Sucuri API key',
					),
					array(
						'key'   => 'SUCURI_API_SECRET',
						'kind'  => 'secret',
						'label' => 'Sucuri API secret',
					),
				),
				'all'         => array(
					'method'    => 'POST',
					'url'       => 'https://waf.sucuri.net/api?v2',
					'body_type' => 'form',
					'body'      => array(
						'k' => '{SUCURI_API_KEY}',
						's' => '{SUCURI_API_SECRET}',
						'a' => 'clear_cache',
					),
				),
				'fingerprint' => array(
					'header'  => 'x-sucuri-cache',
					'pattern' => '/HIT/i',
				),
			),
			// A generic outgoing webhook, for anything without its own layer: a headless front end (Next.js
			// revalidation, a Netlify or Vercel deploy hook), a custom CDN, an internal service. The URL is a
			// secret because it often carries a token. Not tied to a vendor, so there are no docs to verify against.
			'webhook' => array(
				'id'       => 'webhook',
				'label'    => 'Custom webhook',
				'tier'     => Tier::CDN,
				'secrets'  => array( 'PURGE_WEBHOOK_URL' ),
				'settings' => array(),
				'fields'   => array(
					array(
						'key'     => 'PURGE_WEBHOOK_URL',
						'kind'    => 'secret',
						'label'   => 'Webhook URL (https://..., may contain a token)',
						'pattern' => '#^https?://[^\s/]+\S*$#i',
					),
				),
				'all'      => array(
					'method' => 'POST',
					'url'    => '{PURGE_WEBHOOK_URL}',
					'body'   => array(
						'event' => 'purge',
						'all'   => true,
					),
				),
				'url'      => array(
					'method' => 'POST',
					'url'    => '{PURGE_WEBHOOK_URL}',
					'batch'  => 100,
					'body'   => array(
						'event' => 'purge',
						'all'   => false,
						'urls'  => '{urls}',
					),
				),
				'test'     => array(
					'method' => 'POST',
					'url'    => '{PURGE_WEBHOOK_URL}',
					'body'   => array( 'event' => 'test' ),
				),
			),
		);
	}

	/** @return array<string, string> key => 'secret'|'setting', for every writable provider field. */
	public static function writableFields(): array {
		$out = array();
		foreach ( self::all() as $spec ) {
			foreach ( $spec['fields'] as $f ) {
				$out[ $f['key'] ] = $f['kind'];
			}
		}
		return $out;
	}

	/** Whether a value may be saved for a field; a field without a `pattern` accepts anything. */
	public static function accepts( string $key, string $value ): bool {
		foreach ( self::all() as $spec ) {
			foreach ( $spec['fields'] as $f ) {
				if ( $f['key'] === $key && isset( $f['pattern'] ) ) {
					return 1 === preg_match( (string) $f['pattern'], $value );
				}
			}
		}
		return true;
	}
}
