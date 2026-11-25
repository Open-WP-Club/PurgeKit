<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\LayerStats;
use PurgeKit\Layers\Metrics;
use PurgeKit\Layers\Tier;
use PurgeKit\Services;

/** One row per cache layer, for the Compare page, the CSV export and `wp purgekit stats`. */
final class CompareData {
	public function __construct( private Services $services ) {}

	/**
	 * @return array<int,array<string,mixed>> Outermost layer (CDN) first, the order a request travels.
	 */
	public function rows( int $days ): array {
		$s        = $this->services;
		$stats    = LayerStats::summarise( $s->history->since( gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS ) ) );
		$disabled = $s->settings->disabledLayers();
		$rows     = array();
		foreach ( $s->registry->all() as $layer ) {
			$detected = $layer->detect();
			$figures  = array();
			if ( $detected && $layer instanceof Metrics ) {
				try {
					$figures = $layer->metrics();
				} catch ( \Throwable $e ) {
					$figures = array();
				}
			}
			$by_label = array_column( $figures, null, 'label' );
			$st       = $stats[ $layer->id() ] ?? array();
			$rows[]   = array(
				'id'           => $layer->id(),
				'label'        => $layer->label(),
				'tier'         => $layer->tier(),
				'tier_label'   => Tier::label( $layer->tier() ),
				'state'        => ! $detected ? 'not-detected' : ( in_array( $layer->id(), $disabled, true ) ? 'off' : 'active' ),
				'purges'       => (int) ( $st['purges'] ?? 0 ),
				'failed'       => (int) ( $st['failed'] ?? 0 ),
				'success_rate' => $st['success_rate'] ?? null,
				'avg_ms'       => (int) ( $st['avg_ms'] ?? 0 ),
				'slowest_ms'   => (int) ( $st['slowest_ms'] ?? 0 ),
				'full'         => (int) ( $st['full'] ?? 0 ),
				'urls'         => (int) ( $st['urls'] ?? 0 ),
				'last_at'      => $st['last_at'] ?? null,
				'hit_rate'     => isset( $by_label['Hit rate']['percent'] ) ? round( (float) $by_label['Hit rate']['percent'], 1 ) : null,
				'memory'       => isset( $by_label['Memory']['percent'] ) ? round( (float) $by_label['Memory']['percent'], 1 ) : null,
				'figures'      => $figures,
			);
		}
		usort( $rows, static fn( $a, $b ) => $b['tier'] <=> $a['tier'] );
		return $rows;
	}
}
