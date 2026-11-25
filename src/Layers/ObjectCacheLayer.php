<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\Bytes;
use PurgeKit\Core\LayerResult;

final class ObjectCacheLayer extends AbstractLayer implements Metrics {
	private ObjectCacheInfo $info;

	public function __construct( ?ObjectCacheInfo $info = null ) {
		$this->info = $info ?? new WpObjectCacheInfo();
	}

	public function id(): string {
		return 'object-cache';
	}

	public function label(): string {
		return 'Object cache';
	}

	public function tier(): int {
		return Tier::OBJECT;
	}

	public function detect(): bool {
		return true;
	}

	public function purgeAll(): LayerResult {
		wp_cache_flush();
		return $this->ok();
	}

	public function purgeUrls( array $urls ): LayerResult {
		return $this->purgeAll();
	}

	/** A Redis that is in use but silent is a failed check; a nearly full one, or one that is throwing keys away, is a warning. */
	public function test(): LayerResult {
		$d = $this->info->describe();
		if ( ! $d['persistent'] ) {
			return $this->ok( 'No persistent object cache: values are only kept for one page load.' );
		}
		if ( false === $this->info->responding() ) {
			return $this->fail( 'A Redis object cache is installed but the server does not answer, so WordPress is working without it.' );
		}
		$s = $this->info->server();
		if ( null === $s ) {
			return $this->ok( sprintf( 'Persistent object cache (%s).', $d['driver'] ) );
		}
		$notes = array();
		if ( $s['max_memory'] > 0 && $s['used_memory'] / $s['max_memory'] > 0.9 ) {
			$notes[] = sprintf( 'Memory is %.0f%% full.', 100 * $s['used_memory'] / $s['max_memory'] );
		}
		if ( $s['evicted'] > 0 ) {
			$notes[] = sprintf( '%s keys were thrown out to make room; raise maxmemory.', number_format( $s['evicted'] ) );
		}
		$text = sprintf( '%s %s answers, %s keys.', $s['name'], $s['version'], number_format( $s['keys'] ) );
		return new LayerResult( $this->id(), LayerResult::OK, trim( $text . ' ' . implode( ' ', $notes ) ), 0, array() !== $notes );
	}

	public function metrics(): array {
		$d   = $this->info->describe();
		$out = array(
			array(
				'label' => 'Kind',
				'value' => $d['persistent'] ? 'Persistent (survives between requests)' : 'Per request only (nothing is kept between requests)',
			),
		);
		if ( '' !== $d['driver'] ) {
			$driver = $d['driver'] . ( '' !== $d['client'] ? ' · ' . $d['client'] : '' );
			$out[]  = array(
				'label' => 'Driver',
				'value' => $driver,
			);
		}
		$s = $d['persistent'] ? $this->info->server() : null;
		if ( $s ) {
			$out = array_merge( $out, $this->serverMetrics( $s ) );
		}
		if ( ! empty( $d['flush_scope'] ) ) {
			$out[] = array(
				'label' => 'Purging empties',
				'value' => $d['flush_scope'],
			);
		}
		$seen = $d['request_hits'] + $d['request_misses'];
		if ( ! $s && $seen > 0 ) {
			$out[] = array(
				'label' => 'This page load',
				'value' => sprintf( '%d hits, %d misses (%.0f%%)', $d['request_hits'], $d['request_misses'], 100 * $d['request_hits'] / $seen ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $s Normalised server figures.
	 * @return array<int,array<string,mixed>>
	 */
	private function serverMetrics( array $s ): array {
		$out   = array();
		$out[] = array(
			'label' => 'Server',
			'value' => trim( $s['name'] . ' ' . $s['version'] ),
		);
		$look  = $s['hits'] + $s['misses'];
		if ( $look > 0 ) {
			$out[] = array(
				'label'   => 'Hit rate',
				'value'   => sprintf( '%.1f%% (%s hits, %s misses)', 100 * $s['hits'] / $look, number_format( $s['hits'] ), number_format( $s['misses'] ) ),
				'percent' => 100 * $s['hits'] / $look,
				'meter'   => 'quality',
			);
		}
		if ( $s['max_memory'] > 0 ) {
			$out[] = array(
				'label'   => 'Memory',
				'value'   => sprintf( '%s of %s used (%.0f%%)', Bytes::human( $s['used_memory'] ), Bytes::human( $s['max_memory'] ), 100 * $s['used_memory'] / $s['max_memory'] ),
				'percent' => 100 * $s['used_memory'] / $s['max_memory'],
				'meter'   => 'usage',
			);
		} else {
			$out[] = array(
				'label'  => 'Memory',
				'value'  => Bytes::human( $s['used_memory'] ) . ' used, no limit set',
				'number' => $s['used_memory'],
			);
		}
		$out[] = array(
			'label'  => 'Keys',
			'value'  => number_format( $s['keys'] ),
			'number' => $s['keys'],
		);
		$out[] = array(
			'label'  => 'Evicted keys',
			'value'  => number_format( $s['evicted'] ) . ( '' !== $s['policy'] ? ' (policy ' . $s['policy'] . ')' : '' ),
			'number' => $s['evicted'],
		);
		$out[] = array(
			'label' => 'Clients, uptime',
			'value' => sprintf( '%d connected, up %s', $s['clients'], self::duration( $s['uptime'] ) ),
		);
		return $out;
	}

	private static function duration( int $seconds ): string {
		if ( $seconds >= 86400 ) {
			return sprintf( '%d d %d h', intdiv( $seconds, 86400 ), intdiv( $seconds % 86400, 3600 ) );
		}
		if ( $seconds >= 3600 ) {
			return sprintf( '%d h %d min', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
		}
		return sprintf( '%d min', intdiv( $seconds, 60 ) );
	}
}
