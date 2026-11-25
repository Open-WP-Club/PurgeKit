<?php
namespace PurgeKit\Rest;

use PurgeKit\Core\Health;
use PurgeKit\Health\Audit;
use PurgeKit\Layers\CloudflareLayer;

/** The Health tab. GET is local only; POST also asks Cloudflare and Slack, because those are network calls. */
final class HealthController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/health',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => fn() => $this->report( false ),
					'permission_callback' => array( $this, 'can' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => fn() => $this->report( true ),
					'permission_callback' => array( $this, 'can' ),
				),
			)
		);
	}

	/** @return array{checks:array<int,array<string,mixed>>,worst:string,networked:bool} */
	private function report( bool $network ): array {
		$s    = $this->services;
		$now  = time();
		$last = $s->store->get( 'last_run' );
		$all  = Health::checks( is_array( $last ) ? $last : null, $s->guard->reason(), count( $s->registry->active() ) );
		// The "everything is fine" line is replaced by the detailed checks below.
		$all = array_values( array_filter( $all, static fn( $c ) => 'ok' !== $c['id'] ) );

		$names = array();
		foreach ( $s->registry->all() as $l ) {
			$names[ $l->id() ] = $l->label();
		}
		array_push( $all, ...Audit::failingLayers( $s->history->lastPerLayer(), $names ) );

		$key   = (string) $s->secrets->get( 'WEBHOOK_KEY' );
		$at    = $s->store->get( 'webhook_key_at' );
		$all[] = Audit::webhookKey( '' !== $key, strlen( $key ), is_numeric( $at ) ? (int) $at : null, $now );

		$cf = $s->registry->find( 'cloudflare' );
		if ( $cf instanceof CloudflareLayer ) {
			$token = $network && $s->secrets->has( 'CLOUDFLARE_TOKEN' ) ? $cf->tokenInfo() : null;
			$zone  = null;
			if ( $network && $cf->detect() ) {
				$r    = $cf->test();
				$zone = array(
					'status'  => $r->status,
					'message' => $r->message,
				);
			}
			$all[] = Audit::cloudflare( $s->secrets->has( 'CLOUDFLARE_TOKEN' ), '' !== (string) $s->store->get( 'cloudflare_zone', '' ), $token, $zone, $now );
		}

		$channels = $s->notifier->channels();
		$all[]    = Audit::alerts( $channels );
		if ( $network ) {
			$all[] = Audit::slack( $s->notifier->probeSlack() );
		}
		$all[] = Audit::cron( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'scheduled' === $s->settings->mode() || count( $s->deferred->all() ) > 0 || $s->settings->warmEnabled(), is_numeric( $s->store->get( 'cron_last_run' ) ) ? (int) $s->store->get( 'cron_last_run' ) : null, $now );
		$trip  = $s->storm->tripped( $now );
		$all[] = Audit::storm( $trip, $s->settings->stormPause() );

		$all = array_values( array_filter( $all ) );
		if ( array() === array_filter( $all, static fn( $c ) => Health::GOOD !== $c['status'] ) ) {
			array_unshift( $all, Audit::check( 'ok', Health::GOOD, 'Cache purging is working', 'No problems found.' ) );
		}
		return array(
			'checks'    => $all,
			'worst'     => Health::worst( $all ),
			'networked' => $network,
		);
	}
}
