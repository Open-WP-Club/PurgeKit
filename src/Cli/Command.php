<?php
namespace PurgeKit\Cli;

use PurgeKit\Core\Change;
use PurgeKit\Core\Run;
use PurgeKit\Services;

// WP-CLI passes ($args, $assoc_args) to every command, used or not.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
/** Manage PurgeKit from the command line. */
final class Command {
	public function __construct( private Services $services ) {}

	/**
	 * Purge caches.
	 *
	 * ## OPTIONS
	 *
	 * [<url>]
	 * : Purge only this URL.
	 *
	 * [--post=<id>]
	 * : Purge only this post's URL.
	 *
	 * [--network]
	 * : On multisite, purge every site (ignores <url> and --post).
	 *
	 * [--dry-run]
	 * : Show what would be purged, and on which layers, without purging anything.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function flush( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['dry-run'] ) && isset( $assoc_args['network'] ) ) {
			\WP_CLI::error( '--dry-run cannot be combined with --network.' );
		}
		if ( isset( $assoc_args['network'] ) ) {
			if ( ! is_multisite() ) {
				\WP_CLI::error( 'This is not a multisite installation.' );
			}
			$bad = 0;
			foreach ( ( new \PurgeKit\Network\SiteRunner() )->flushAll( 'cli' ) as $row ) {
				\WP_CLI::line( sprintf( '%s: %s', $row['url'], $row['status'] ) );
				$bad += in_array( $row['status'], array( Run::OK, Run::PARTIAL, Run::SKIPPED ), true ) ? 0 : 1;
			}
			if ( $bad ) {
				\WP_CLI::warning( "$bad site(s) failed or were locked." );
				\WP_CLI::halt( 1 );
			}
			\WP_CLI::success( 'Network flush finished.' );
			return;
		}
		$url = $args[0] ?? null;
		if ( isset( $assoc_args['post'] ) ) {
			$url = get_permalink( (int) $assoc_args['post'] );
			if ( ! $url ) {
				\WP_CLI::error( 'Post not found.' );
			}
		}
		if ( isset( $assoc_args['dry-run'] ) ) {
			$this->dry_run( $this->services->flusher->previewTarget( $url ? array( (string) $url ) : null ) );
			return;
		}
		$run = $url ? $this->services->flusher->flushUrls( 'cli', array( (string) $url ) ) : $this->services->flusher->flushAll( 'cli' );

		if ( Run::LOCKED === $run->status ) {
			\WP_CLI::error( 'Another flush is already running.' );
		}
		foreach ( $run->results as $r ) {
			\WP_CLI::line( sprintf( '%s: %s %s (%d ms)', $r->layer, $r->status, $r->message, $r->ms ) );
		}
		if ( Run::OK === $run->status || Run::SKIPPED === $run->status ) {
			\WP_CLI::success( 'Flush ' . $run->status . '.' );
			return;
		}
		\WP_CLI::warning( 'Flush ' . $run->status . '.' );
		\WP_CLI::halt( 1 );
	}

	/** @param array<string,mixed> $preview */
	private function dry_run( array $preview ): void {
		if ( $preview['empty'] ) {
			\WP_CLI::success( 'Dry run: nothing would be purged.' );
			return;
		}
		foreach ( $preview['jobs'] as $job ) {
			\WP_CLI::line( $job['all'] ? 'Would purge everything on:' : sprintf( 'Would purge %d URL(s) on:', $job['urls_total'] ) );
			foreach ( $job['layers'] as $layer ) {
				\WP_CLI::line( sprintf( '  %-24s %s', $layer['id'], $layer['skipped'] ? '(skipped: ' . $layer['skipped'] . ')' : 'will be purged' ) );
			}
			foreach ( $job['urls'] as $url ) {
				\WP_CLI::line( '  ' . $url );
			}
			if ( $job['urls_total'] > count( $job['urls'] ) ) {
				\WP_CLI::line( sprintf( '  ... and %d more', $job['urls_total'] - count( $job['urls'] ) ) );
			}
		}
		\WP_CLI::success( 'Dry run: nothing was purged.' );
	}

	/** Show pending changes, mode and the last run. */
	public function status( array $args, array $assoc_args ): void {
		$last = $this->services->history->recent( 1, 0 );
		\WP_CLI::line( 'Pending changes: ' . $this->services->queue->count() );
		\WP_CLI::line( 'Mode: ' . $this->services->settings->mode() );
		\WP_CLI::line( 'Active layers: ' . count( $this->services->registry->active() ) );
		\WP_CLI::line( 'Last run: ' . ( $last ? $last[0]['created_at'] . ' ' . $last[0]['status'] : 'never' ) );
	}

	/** List every cache layer and whether it was detected. */
	public function layers( array $args, array $assoc_args ): void {
		foreach ( $this->services->registry->all() as $layer ) {
			\WP_CLI::line( sprintf( '%-18s tier %2d  %s', $layer->id(), $layer->tier(), $layer->detect() ? 'detected' : '-' ) );
		}
	}

	/**
	 * Show how each cache layer did: purges, failures, speed, and live figures where the cache has them.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : 7, 30 or 90. Default 30.
	 *
	 * [--live]
	 * : Also print the live figures of each cache (hit rate, memory, keys). From the command line OPcache and APCu are not the web server's, so their figures can be empty.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Default table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function stats( array $args, array $assoc_args ): void {
		$days = (int) ( $assoc_args['days'] ?? 30 );
		if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) {
			\WP_CLI::error( '--days must be 7, 30 or 90.' );
		}
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = ( new \PurgeKit\Wp\CompareData( $this->services ) )->rows( $days );
		$items  = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'layer'      => $r['label'],
				'state'      => $r['state'],
				'purges'     => $r['purges'],
				'failed'     => $r['failed'],
				'success'    => null === $r['success_rate'] ? '' : $r['success_rate'] . '%',
				'avg_ms'     => $r['avg_ms'],
				'slowest_ms' => $r['slowest_ms'],
				'hit_rate'   => null === $r['hit_rate'] ? '' : $r['hit_rate'] . '%',
				'memory'     => null === $r['memory'] ? '' : $r['memory'] . '%',
			);
		}
		\WP_CLI\Utils\format_items( $format, $items, array( 'layer', 'state', 'purges', 'failed', 'success', 'avg_ms', 'slowest_ms', 'hit_rate', 'memory' ) );
		if ( isset( $assoc_args['live'] ) ) {
			foreach ( $rows as $r ) {
				foreach ( $r['figures'] as $f ) {
					\WP_CLI::line( sprintf( '%s / %s: %s', $r['label'], $f['label'], $f['value'] ) );
				}
			}
		}
	}

	/** Scan theme and plugin files for changes WordPress never saw. */
	public function scan( array $args, array $assoc_args ): void {
		if ( $this->services->scanner->scan() ) {
			$this->services->queue->add( Change::siteWide( 'files' ) );
			\WP_CLI::success( 'Files changed; a flush is pending.' );
			return;
		}
		\WP_CLI::line( 'No file changes.' );
	}

	/** Warm the cache for queued URLs now. */
	public function warm( array $args, array $assoc_args ): void {
		$done = 0;
		while ( $this->services->warmer->pending() > 0 ) {
			$done += $this->services->warmer->processBatch();
		}
		\WP_CLI::success( "Warmed $done URLs." );
	}
}
