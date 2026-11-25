<?php
namespace PurgeKit\Core;

/** The weekly summary as text. Pure: it is given the numbers and writes them down. */
final class Digest {
	/**
	 * @param array<string,mixed>                $impact  Impact::summarise() for the period.
	 * @param array<string,array<string,mixed>>  $layers  LayerStats::summarise() for the period.
	 * @param array<string,string>               $labels  Layer id => name.
	 * @param array<string,mixed>|null           $probe   The latest site probe, if there is one.
	 * @return array{subject:string,body:string,event:array<string,mixed>}
	 */
	public static function build( string $site_name, string $site_url, array $impact, array $layers, array $labels, ?array $probe, int $days ): array {
		$lines   = array();
		$lines[] = sprintf( 'Site: %s', $site_url );
		$lines[] = sprintf( 'Last %d days: %d flushes (%d purged some pages, %d purged everything), %d with problems.', $days, (int) ( $impact['flushes'] ?? 0 ), (int) ( $impact['targeted'] ?? 0 ), (int) ( $impact['full'] ?? 0 ), (int) ( $impact['problems'] ?? 0 ) );
		if ( ! empty( $impact['urls'] ) ) {
			$lines[] = sprintf( '%d pages were purged one by one; everything else stayed cached.', (int) $impact['urls'] );
		}
		$rows = array();
		foreach ( $layers as $id => $s ) {
			if ( 0 === $s['purges'] + $s['failed'] ) {
				continue;
			}
			$rows[ $id ] = $s;
		}
		uasort(
			$rows,
			static function ( $a, $b ) {
				$by_failed = $b['failed'] <=> $a['failed'];
				return 0 !== $by_failed ? $by_failed : $b['purges'] <=> $a['purges'];
			}
		);
		if ( $rows ) {
			$lines[] = '';
			$lines[] = 'By cache layer:';
			foreach ( $rows as $id => $s ) {
				$lines[] = sprintf(
					'- %s: %d purges, %d failed%s%s',
					$labels[ $id ] ?? $id,
					$s['purges'],
					$s['failed'],
					null === $s['success_rate'] ? '' : sprintf( ' (%d%% worked)', $s['success_rate'] ),
					$s['avg_ms'] > 0 ? sprintf( ', %d ms on average', $s['avg_ms'] ) : ''
				);
			}
		}
		if ( $probe && ! empty( $probe['total'] ) ) {
			$lines[] = '';
			$lines[] = sprintf( 'Pages answered from a cache: %d of %d checked (%d%%).', (int) $probe['cached'], (int) $probe['total'], (int) $probe['percent'] );
			foreach ( (array) ( $probe['pages'] ?? array() ) as $p ) {
				if ( \PurgeKit\Inspector\Cacheability::CACHED !== ( $p['verdict'] ?? '' ) && \PurgeKit\Inspector\Cacheability::ERROR !== ( $p['verdict'] ?? '' ) ) {
					$lines[] = '- Not cached: ' . $p['url'];
				}
			}
		}
		return array(
			'subject' => sprintf( '[%s] PurgeKit: weekly summary', $site_name ),
			'body'    => implode( "\n", $lines ),
			'event'   => array(
				'event'   => 'weekly_digest',
				'days'    => $days,
				'flushes' => (int) ( $impact['flushes'] ?? 0 ),
				'failed'  => array_map( static fn( $s ) => $s['failed'], $rows ),
			),
		);
	}
}
