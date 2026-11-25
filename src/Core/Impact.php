<?php
namespace PurgeKit\Core;

/** Turns history rows into the numbers on the Impact tab. Pure: no database, no WordPress. */
final class Impact {
	/**
	 * @param array<int,array<string,mixed>> $rows History rows as Repository::since() returns them.
	 * @return array<string,mixed>
	 */
	public static function summarise( array $rows, int $now, int $days ): array {
		$days  = max( 1, min( 365, $days ) );
		$empty = array(
			'full'     => 0,
			'targeted' => 0,
			'problems' => 0,
		);
		$per   = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$per[ gmdate( 'Y-m-d', $now - $i * 86400 ) ] = $empty;
		}
		$total   = array(
			'flushes'      => 0,
			'full'         => 0,
			'targeted'     => 0,
			'urls'         => 0,
			'problems'     => 0,
			'skipped'      => 0,
			'layer_purges' => 0,
			'ms'           => 0,
			'timed'        => 0,
		);
		$sources = array();
		$changes = array();
		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( Run::SKIPPED === $status ) {
				++$total['skipped'];
				continue; // Nothing was purged, so it is neither a saving nor a cost.
			}
			++$total['flushes'];
			$full = ! empty( $row['all'] );
			$kind = $full ? 'full' : 'targeted';
			++$total[ $kind ];
			if ( ! $full ) {
				$total['urls'] += (int) ( $row['urls_total'] ?? count( (array) ( $row['urls'] ?? array() ) ) );
			}
			$bad = in_array( $status, array( Run::FAILED, Run::PARTIAL ), true );
			if ( $bad ) {
				++$total['problems'];
			}
			foreach ( (array) ( $row['results'] ?? array() ) as $r ) {
				if ( 'ok' === ( $r['status'] ?? '' ) ) {
					++$total['layer_purges'];
				}
			}
			if ( (int) ( $row['duration_ms'] ?? 0 ) > 0 ) {
				$total['ms'] += (int) $row['duration_ms'];
				++$total['timed'];
			}
			$source             = (string) ( $row['source'] ?? '' );
			$sources[ $source ] = ( $sources[ $source ] ?? 0 ) + 1;
			foreach ( (array) ( $row['trigger']['changes'] ?? array() ) as $type ) {
				$changes[ (string) $type ] = ( $changes[ (string) $type ] ?? 0 ) + 1;
			}
			$day = substr( (string) ( $row['created_at'] ?? '' ), 0, 10 );
			if ( isset( $per[ $day ] ) ) {
				++$per[ $day ][ $kind ];
				$per[ $day ]['problems'] += $bad ? 1 : 0;
			}
		}
		arsort( $sources );
		arsort( $changes );
		$out = array();
		foreach ( $per as $date => $v ) {
			$out[] = array( 'date' => $date ) + $v;
		}
		return array(
			'days'           => $days,
			'flushes'        => $total['flushes'],
			'full'           => $total['full'],
			'targeted'       => $total['targeted'],
			// Share of flushes that did not have to purge everything.
			'targeted_share' => $total['flushes'] > 0 ? (int) round( 100 * $total['targeted'] / $total['flushes'] ) : 0,
			'urls'           => $total['urls'],
			'problems'       => $total['problems'],
			'skipped'        => $total['skipped'],
			'layer_purges'   => $total['layer_purges'],
			'avg_ms'         => $total['timed'] > 0 ? (int) round( $total['ms'] / $total['timed'] ) : 0,
			'by_source'      => array_slice( $sources, 0, 8, true ),
			'by_change'      => array_slice( $changes, 0, 8, true ),
			'per_day'        => $out,
		);
	}
}
