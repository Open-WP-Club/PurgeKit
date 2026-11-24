<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Store;

/**
 * Empties APCu, PHP's shared in-memory data cache. APCu is shared by everything on the same PHP pool,
 * so this also drops other applications' entries; that is why it is off until switched on, and why it
 * only runs when everything is purged. Like OPcache it is per PHP pool and not reachable from WP-CLI.
 */
final class ApcuLayer extends AbstractLayer {
	public function __construct( private Store $settings, private Apcu $apcu ) {}

	public function id(): string {
		return 'apcu';
	}

	public function label(): string {
		return 'APCu';
	}

	public function tier(): int {
		return Tier::APCU;
	}

	public function detect(): bool {
		return (bool) $this->settings->get( 'apcu_enabled', false );
	}

	public function purgeUrls( array $urls ): LayerResult {
		return new LayerResult( $this->id(), LayerResult::SKIPPED, 'APCu is only emptied when everything is purged.' );
	}

	public function purgeAll(): LayerResult {
		$status = $this->apcu->status();
		if ( null === $status ) {
			return $this->fail( 'APCu is not active in this PHP process. The command line has its own APCu, so purge from the admin or a web request.' );
		}
		if ( ! $this->apcu->clear() ) {
			return $this->fail( 'APCu refused to clear its cache.' );
		}
		return $this->ok( sprintf( 'Cleared %d entries.', $status['entries'] ) );
	}

	public function test(): LayerResult {
		$status = $this->apcu->status();
		if ( null === $status ) {
			return new LayerResult( $this->id(), LayerResult::SKIPPED, 'APCu is not active in this PHP process.' );
		}
		$lookups = $status['hits'] + $status['misses'];
		$parts   = array( sprintf( '%d entries', $status['entries'] ) );
		if ( $lookups > 0 ) {
			$parts[] = sprintf( 'hit rate %.1f%%', 100 * $status['hits'] / $lookups );
		}
		$note = '';
		if ( $status['mem_total'] > 0 ) {
			$used    = 100 * $status['mem_used'] / $status['mem_total'];
			$parts[] = sprintf( 'memory %.0f%% used', $used );
			$note    = $used > 95 ? ' The cache is almost full; raise apc.shm_size.' : '';
		}
		return $this->ok( implode( ', ', $parts ) . '.' . $note );
	}
}
