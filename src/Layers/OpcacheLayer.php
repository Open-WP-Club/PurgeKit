<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Store;

/**
 * Resets PHP's compiled-code cache. It only runs when everything is purged (a theme or plugin
 * changed), never for a single page, because every reset makes the next requests recompile the code.
 * Off until it is switched on in Settings. A reset only reaches the PHP process pool that runs it, so
 * it does nothing useful from WP-CLI, which has its own OPcache.
 */
final class OpcacheLayer extends AbstractLayer {
	/** @param bool|null $cli Whether this is the command line; null looks at PHP itself. */
	public function __construct( private Store $settings, private Opcache $opcache, private ?bool $cli = null ) {}

	private function isCli(): bool {
		return $this->cli ?? ( 'cli' === PHP_SAPI );
	}

	public function id(): string {
		return 'opcache';
	}

	public function label(): string {
		return 'PHP OPcache';
	}

	public function tier(): int {
		return Tier::OPCACHE;
	}

	public function detect(): bool {
		return (bool) $this->settings->get( 'opcache_enabled', false );
	}

	public function purgeUrls( array $urls ): LayerResult {
		return new LayerResult( $this->id(), LayerResult::SKIPPED, 'OPcache is only reset when everything is purged.' );
	}

	public function purgeAll(): LayerResult {
		$status = $this->opcache->status();
		if ( null === $status ) {
			if ( $this->isCli() ) {
				// Not a fault of the cache: the command line simply cannot reach the web server's OPcache.
				return new LayerResult( $this->id(), LayerResult::SKIPPED, 'The command line has its own OPcache, so it was left alone. Purge from the admin or a web request.' );
			}
			return $this->fail( 'OPcache is not active in this PHP process.' );
		}
		if ( ! $this->opcache->reset() ) {
			return $this->fail( 'PHP refused to reset OPcache. The host may restrict it (opcache.restrict_api).' );
		}
		return $this->ok( sprintf( 'Reset %d cached scripts.', (int) ( $status['opcache_statistics']['num_cached_scripts'] ?? 0 ) ) );
	}

	/** Reports what OPcache is doing and warns about settings that cause trouble. */
	public function test(): LayerResult {
		$status = $this->opcache->status();
		if ( null === $status ) {
			return new LayerResult( $this->id(), LayerResult::SKIPPED, 'OPcache is not active in this PHP process.' );
		}
		$memory = (array) ( $status['memory_usage'] ?? array() );
		$stats  = (array) ( $status['opcache_statistics'] ?? array() );
		$config = $this->opcache->config();

		$used   = (float) ( $memory['used_memory'] ?? 0 );
		$free   = (float) ( $memory['free_memory'] ?? 0 );
		$wasted = (float) ( $memory['current_wasted_percentage'] ?? 0 );
		$total  = $used + $free + (float) ( $memory['wasted_memory'] ?? 0 );

		$parts   = array();
		$parts[] = sprintf( '%d scripts cached', (int) ( $stats['num_cached_scripts'] ?? 0 ) );
		$parts[] = sprintf( 'hit rate %.1f%%', (float) ( $stats['opcache_hit_rate'] ?? 0 ) );
		if ( $total > 0 ) {
			$parts[] = sprintf( 'memory %.0f%% used', 100 * $used / $total );
		}
		$notes = array();
		$warn  = false;
		if ( (int) ( $stats['oom_restarts'] ?? 0 ) > 0 || ( $total > 0 && $free / $total < 0.05 ) ) {
			$notes[] = 'The cache is full; raise opcache.memory_consumption.';
			$warn    = true;
		}
		if ( $wasted > 10 ) {
			$notes[] = sprintf( '%.0f%% of the memory is wasted; a reset frees it.', $wasted );
		}
		if ( isset( $config['validate_timestamps'] ) && ! filter_var( $config['validate_timestamps'], FILTER_VALIDATE_BOOLEAN ) ) {
			$notes[] = 'Files are not re-checked (validate_timestamps is off), so updated code only goes live after a reset.';
		}
		if ( ! empty( $config['restrict_api'] ) ) {
			$notes[] = 'opcache.restrict_api is set; a reset from this site may be refused.';
		}
		return new LayerResult( $this->id(), LayerResult::OK, implode( ', ', $parts ) . ( $notes ? '. ' . implode( ' ', $notes ) : '' ), 0, $warn );
	}
}
