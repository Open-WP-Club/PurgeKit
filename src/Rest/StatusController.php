<?php
namespace PurgeKit\Rest;

final class StatusController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	public function get(): array {
		$last = $this->services->history->recent( 1, 0 );
		return array(
			'pending'       => $this->services->queue->count(),
			'mode'          => $this->services->settings->mode(),
			'layers_active' => count( $this->services->registry->active() ),
			'last_run'      => $last[0] ?? null,
		);
	}
}
