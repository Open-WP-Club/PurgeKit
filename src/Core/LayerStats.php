<?php
namespace PurgeKit\Core;

/** Per layer, what the history says about purges in a period: how many, how many failed, how fast. Pure. */
final class LayerStats {
	/**
	 * @param array<int,array<string,mixed>> $rows History rows as Repository::since() returns them.
	 * @return array<string,array{purges:int,failed:int,skipped:int,full:int,urls:int,avg_ms:int,slowest_ms:int,success_rate:?int,last_at:?string}>
	 */
	public static function summarise( array $rows ): array {
		$acc = array();
		foreach ( $rows as $row ) {
			$full = ! empty( $row['all'] );
			$urls = (int) ( $row['urls_total'] ?? count( (array) ( $row['urls'] ?? array() ) ) );
			foreach ( (array) ( $row['results'] ?? array() ) as $r ) {
				$id = is_array( $r ) ? (string) ( $r['layer'] ?? '' ) : '';
				if ( '' === $id ) {
					continue;
				}
				$a      = $acc[ $id ] ?? array(
					'purges'     => 0,
					'failed'     => 0,
					'skipped'    => 0,
					'full'       => 0,
					'urls'       => 0,
					'ms_total'   => 0,
					'ms_count'   => 0,
					'slowest_ms' => 0,
					'last_at'    => null,
				);
				$status = (string) ( $r['status'] ?? '' );
				if ( LayerResult::SKIPPED === $status ) {
					++$a['skipped'];
				} elseif ( LayerResult::FAILED === $status ) {
					++$a['failed'];
				} elseif ( LayerResult::OK === $status ) {
					++$a['purges'];
					$full ? ++$a['full'] : $a['urls'] += $urls;
					$at                                = max( (string) $a['last_at'], (string) ( $row['created_at'] ?? '' ) );
					$a['last_at']                      = '' === $at ? null : $at;
				}
				$ms = (int) ( $r['ms'] ?? 0 );
				if ( LayerResult::SKIPPED !== $status && $ms > 0 ) {
					$a['ms_total'] += $ms;
					++$a['ms_count'];
					$a['slowest_ms'] = max( $a['slowest_ms'], $ms );
				}
				$acc[ $id ] = $a;
			}
		}
		$out = array();
		foreach ( $acc as $id => $a ) {
			$done       = $a['purges'] + $a['failed'];
			$out[ $id ] = array(
				'purges'       => $a['purges'],
				'failed'       => $a['failed'],
				'skipped'      => $a['skipped'],
				'full'         => $a['full'],
				'urls'         => $a['urls'],
				'avg_ms'       => $a['ms_count'] > 0 ? (int) round( $a['ms_total'] / $a['ms_count'] ) : 0,
				'slowest_ms'   => $a['slowest_ms'],
				'success_rate' => $done > 0 ? (int) round( 100 * $a['purges'] / $done ) : null,
				'last_at'      => $a['last_at'],
			);
		}
		return $out;
	}
}
