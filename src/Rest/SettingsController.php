<?php
namespace PurgeKit\Rest;

use PurgeKit\Alerts\Notifier;
use PurgeKit\Layers\ProviderSpecs;

final class SettingsController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'can' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'can' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/alerts/test',
			array(
				'methods'             => 'POST',
				'callback'            => fn() => array( 'channels' => $this->services->notifier->channels() ) + array( 'results' => $this->services->notifier->sendTest() ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/settings/cloudflare/find-zone',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'find_zone' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/settings/webhook-key/rotate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rotate' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	/** Secrets are never returned, only whether they are set. */
	public function get(): array {
		return $this->services->settings->all() + array(
			'cloudflare_token_set' => $this->services->secrets->has( 'CLOUDFLARE_TOKEN' ),
			'webhook_key_set'      => $this->services->secrets->has( 'WEBHOOK_KEY' ),
			'slack_webhook_set'    => $this->services->secrets->has( \PurgeKit\Alerts\Notifier::SLACK_SECRET ),
			'providers'            => $this->providers(),
			'webhook_url'          => rest_url( self::NS . '/webhook/flush' ),
			'multisite'            => is_multisite(),
			'overridden'           => $this->services->settings->overridden(),
			'guard'                => array(
				'paused'        => $this->services->guard->reason(),
				'environment'   => wp_get_environment_type(),
				'recorded_host' => $this->services->settings->homeHost(),
				'host'          => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			),
		);
	}

	public function save( \WP_REST_Request $req ): array {
		$body = (array) $req->get_json_params();
		// Reset first, so a patch in the same request can still pin a new value.
		$this->services->settings->reset( (array) ( $body['reset'] ?? array() ) );
		$this->services->settings->update( $body );
		if ( ! empty( $body['adopt_home'] ) ) {
			// "This is the real site now": remember the current address instead of the old one.
			$this->services->settings->setHomeHost( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		if ( isset( $body['cloudflare_token'] ) && is_string( $body['cloudflare_token'] ) && '' !== $body['cloudflare_token'] ) {
			$this->services->secrets->set( 'CLOUDFLARE_TOKEN', $body['cloudflare_token'] );
		}
		$this->save_providers( (array) ( $body['providers'] ?? array() ), (array) ( $body['clear_provider_fields'] ?? array() ) );
		$slack = $body['slack_webhook_url'] ?? null;
		if ( is_string( $slack ) && '' !== trim( $slack ) && Notifier::isSlackUrl( trim( $slack ) ) ) {
			$this->services->secrets->set( Notifier::SLACK_SECRET, trim( $slack ) );
		}
		if ( ! empty( $body['clear_slack_webhook'] ) ) {
			$this->services->secrets->delete( Notifier::SLACK_SECRET );
		}
		$this->services->cron->sync();
		return $this->get();
	}

	/** Finds the zone ID for this site's domain. Returns it without saving, so the user can review it. */
	public function find_zone( \WP_REST_Request $req ): array|\WP_Error {
		$layer = $this->services->registry->find( 'cloudflare' );
		if ( ! $layer instanceof \PurgeKit\Layers\CloudflareLayer ) {
			return new \WP_Error( 'purgekit_no_layer', 'Cloudflare layer unavailable', array( 'status' => 404 ) );
		}
		$token = $req->get_param( 'token' );
		$token = is_string( $token ) && '' !== $token ? $token : null;
		return $layer->findZone( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $token );
	}

	/**
	 * What the form needs per provider. Secret values are never returned, only whether each is set.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function providers(): array {
		$out = array();
		foreach ( ProviderSpecs::all() as $spec ) {
			$fields = array();
			foreach ( $spec['fields'] as $f ) {
				$row = array(
					'key'   => $f['key'],
					'kind'  => $f['kind'],
					'label' => $f['label'],
				);
				if ( 'secret' === $f['kind'] ) {
					$row['set'] = $this->services->secrets->has( $f['key'] );
				} else {
					$row['value'] = (string) $this->services->store->get( $f['key'], '' );
				}
				$fields[] = $row;
			}
			$layer = $this->services->registry->find( $spec['id'] );
			$out[] = array(
				'id'       => $spec['id'],
				'label'    => $spec['label'],
				'detected' => $layer ? $layer->detect() : false,
				'fields'   => $fields,
			);
		}
		return $out;
	}

	/**
	 * Only keys listed in ProviderSpecs can be written. Blank secrets are ignored (keep the stored one).
	 *
	 * @param array<string, mixed> $values
	 * @param string[]             $clear
	 */
	private function save_providers( array $values, array $clear ): void {
		$writable = ProviderSpecs::writableFields();
		foreach ( $values as $key => $value ) {
			if ( ! isset( $writable[ $key ] ) || ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' !== $value && ! ProviderSpecs::accepts( (string) $key, $value ) ) {
				continue; // Not a valid value for this field (for example a webhook that is not an http(s) URL).
			}
			if ( 'secret' === $writable[ $key ] ) {
				if ( '' !== $value ) {
					$this->services->secrets->set( $key, $value );
				}
			} else {
				$this->services->store->set( $key, $value );
			}
		}
		foreach ( $clear as $key ) {
			if ( is_string( $key ) && 'secret' === ( $writable[ $key ] ?? '' ) ) {
				$this->services->secrets->delete( $key );
			}
		}
	}

	public function rotate(): array {
		$key = wp_generate_password( 40, false );
		$this->services->secrets->set( 'WEBHOOK_KEY', $key );
		return array(
			'key' => $key,
			'url' => rest_url( self::NS . '/webhook/flush' ),
		);
	}
}
