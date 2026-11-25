<?php
namespace PurgeKit\Inspector;

use PurgeKit\Core\MetricSeries;
use PurgeKit\Core\Store;

/**
 * Looks at a few of the site's own pages the way a visitor does and records which ones a cache answered,
 * and for the ones none did, what stands in the way. The share of cached pages is kept over time.
 */
final class SiteProbe {
	private const LAST   = 'probe_last';
	private const SERIES = 'probe_series';
	public const GAP     = 3000;  // Seconds between two automatic runs.
	public const MAX     = 8;     // Pages per run: each costs two requests.

	public function __construct( private Cacheability $cacheability, private Store $store ) {}

	/**
	 * @param string[] $urls
	 * @return array<string,mixed>|null Null when it ran less than ~50 minutes ago and was not forced.
	 */
	public function run( array $urls, int $now, bool $force = false ): ?array {
		$last = $this->latest();
		if ( ! $force && $last && $now - (int) $last['at'] < self::GAP ) {
			return null;
		}
		$pages  = array();
		$cached = 0;
		$total  = 0;
		foreach ( array_slice( array_values( array_unique( $urls ) ), 0, self::MAX ) as $url ) {
			$r       = $this->cacheability->check( $url );
			$verdict = (string) ( $r['verdict'] ?? Cacheability::ERROR );
			if ( Cacheability::ERROR !== $verdict ) {
				++$total;
				$cached += Cacheability::CACHED === $verdict ? 1 : 0;
			}
			$reasons = array();
			foreach ( (array) ( $r['findings'] ?? array() ) as $f ) {
				if ( in_array( $f['severity'] ?? '', array( Cacheability::BLOCKER, Cacheability::WARNING ), true ) ) {
					$reasons[] = (string) $f['message'];
				}
			}
			$pages[] = array(
				'url'      => $url,
				'verdict'  => $verdict,
				'evidence' => array_slice( array_map( 'strval', (array) ( $r['evidence'] ?? array() ) ), 0, 3 ),
				'reasons'  => array_slice( $reasons, 0, 3 ),
				'message'  => (string) ( $r['message'] ?? '' ),
			);
		}
		$percent = $total > 0 ? round( 100 * $cached / $total, 1 ) : 0.0;
		$result  = array(
			'at'      => $now,
			'pages'   => $pages,
			'cached'  => $cached,
			'total'   => $total,
			'percent' => $percent,
		);
		$this->store->set( self::LAST, $result );
		if ( $total > 0 ) {
			list( $all ) = MetricSeries::record( (array) $this->store->get( self::SERIES, array() ), 'site', array( 'Pages answered from a cache' => $percent ), $now );
			$this->store->set( self::SERIES, $all );
		}
		return $result;
	}

	/** @return array<string,mixed>|null */
	public function latest(): ?array {
		$v = $this->store->get( self::LAST );
		return is_array( $v ) && isset( $v['at'] ) ? $v : null;
	}

	/** @return array<int,array{0:int,1:float}> */
	public function series(): array {
		$all = (array) $this->store->get( self::SERIES, array() );
		return (array) ( $all['site']['series']['Pages answered from a cache'] ?? array() );
	}
}
