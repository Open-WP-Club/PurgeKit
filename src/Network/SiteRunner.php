<?php
namespace PurgeKit\Network;

use PurgeKit\Services;

/** Runs work on every site of a multisite network, with a fresh object graph per site. */
final class SiteRunner {
	/**
	 * @param callable(int, Services): mixed $callback
	 * @return array<int, mixed> Results keyed by blog ID. Sites where PurgeKit is not active are skipped.
	 */
	public function eachSite( callable $callback ): array {
		$out = array();
		foreach ( $this->siteIds() as $blog_id ) {
			switch_to_blog( $blog_id );
			try {
				if ( $this->activeHere() ) {
					$out[ $blog_id ] = $callback( $blog_id, Services::build() );
				}
			} finally {
				restore_current_blog();
			}
		}
		return $out;
	}

	/** @return array<int, array{blog: int, url: string, status: string}> One failing site does not stop the others. */
	public function flushAll( string $source ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // A big network can take minutes; this only lifts PHP's own limit.
		}
		return array_values(
			$this->eachSite(
				static function ( int $blog_id, Services $s ) use ( $source ): array {
					try {
						$status = $s->flusher->flushAll( $source )->status;
					} catch ( \Throwable $e ) {
						$status = 'failed';
					}
					return array(
						'blog'   => $blog_id,
						'url'    => home_url( '/' ),
						'status' => $status,
					);
				}
			)
		);
	}

	/** @return int[] */
	private function siteIds(): array {
		return array_map(
			'intval',
			get_sites(
				array(
					'fields'   => 'ids',
					'number'   => 0,
					'deleted'  => 0,
					'archived' => 0,
					'spam'     => 0,
				)
			)
		);
	}

	private function activeHere(): bool {
		return \PurgeKit\Plugin::network_active() || in_array( plugin_basename( PURGEKIT_FILE ), (array) get_option( 'active_plugins', array() ), true );
	}
}
