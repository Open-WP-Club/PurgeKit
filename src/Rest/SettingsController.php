<?php
namespace PurgeKit\Rest;

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
		);
	}

	public function save( \WP_REST_Request $req ): array {
		$body = (array) $req->get_json_params();
		$this->services->settings->update( $body );
		if ( isset( $body['cloudflare_token'] ) && is_string( $body['cloudflare_token'] ) && '' !== $body['cloudflare_token'] ) {
			$this->services->secrets->set( 'CLOUDFLARE_TOKEN', $body['cloudflare_token'] );
		}
		$this->services->cron->sync();
		return $this->get();
	}

	public function rotate(): array {
		$key = wp_generate_password( 40, false );
		$this->services->secrets->set( 'WEBHOOK_KEY', $key );
		return array( 'key' => $key );
	}
}
