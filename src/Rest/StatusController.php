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
		$s       = $this->services;
		$last    = $s->history->recent( 1, 0 );
		$recent  = $s->history->lastPerLayer();
		$checks  = (array) $s->store->get( 'layer_checks', array() );
		$off_ids = $s->settings->disabledLayers();
		$active  = array();
		$failing = array();
		$off     = array();
		$warning = array();
		foreach ( $s->registry->active() as $layer ) {
			$id   = $layer->id();
			$item = array(
				'id'      => $id,
				'label'   => $layer->label(),
				'message' => (string) ( $recent[ $id ]['message'] ?? '' ),
			);
			if ( in_array( $id, $off_ids, true ) ) {
				// Switched off in the layer map: it is not purged, so an old failure is not a problem right now.
				if ( 'failed' === ( $recent[ $id ]['status'] ?? '' ) ) {
					$off[] = $item;
				}
				continue;
			}
			$active[] = $layer;
			if ( 'failed' === ( $recent[ $id ]['status'] ?? '' ) ) {
				$failing[] = $item;
			}
			if ( ! empty( $checks[ $id ]['warn'] ) ) {
				$warning[] = array( 'message' => (string) ( $checks[ $id ]['message'] ?? '' ) ) + $item;
			}
		}
		$storm = $s->storm->tripped( time() );
		return array(
			'pending'        => $s->queue->count(),
			'mode'           => $s->settings->mode(),
			'schedule'       => $s->settings->schedule(),
			'layers_active'  => count( $active ),
			'layers_failing' => count( $failing ),
			'failing_layers' => $failing,
			'off_failing'    => $off,
			'warning_layers' => $warning,
			'last_run'       => $last[0] ?? null,
			'stats'          => $s->history->stats(),
			'recent'         => $s->history->recent( 5, 0 ),
			'delayed'        => count( $s->deferred->all() ),
			'storm'          => $storm ? $storm + array( 'paused' => $s->settings->stormPause() && 'auto' === $s->settings->mode() ) : null,
			'paused'         => $s->guard->reason(),
			'onboarded'      => $s->settings->onboarded(),
		);
	}
}
