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
		$active  = $s->registry->active();
		$recent  = $s->history->lastPerLayer();
		$failing = 0;
		foreach ( $active as $layer ) {
			if ( 'failed' === ( $recent[ $layer->id() ]['status'] ?? '' ) ) {
				++$failing;
			}
		}
		$storm = $s->storm->tripped( time() );
		return array(
			'pending'        => $s->queue->count(),
			'mode'           => $s->settings->mode(),
			'schedule'       => $s->settings->schedule(),
			'layers_active'  => count( $active ),
			'layers_failing' => $failing,
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
