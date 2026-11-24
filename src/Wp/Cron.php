<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Change;
use PurgeKit\Core\Flusher;
use PurgeKit\Core\Queue;
use PurgeKit\Detect\FileScanner;
use PurgeKit\History\Repository;
use PurgeKit\Settings\Settings;

final class Cron {
	public const FLUSH = 'purgekit_scheduled_flush';
	public const SCAN  = 'purgekit_scan';
	public const PRUNE = 'purgekit_prune';

	private const RECURRENCE = array(
		'hourly' => 'hourly',
		'3h'     => 'purgekit_3h',
		'6h'     => 'purgekit_6h',
		'12h'    => 'purgekit_12h',
		'daily'  => 'daily',
		'weekly' => 'purgekit_weekly',
	);

	public function __construct(
		private Settings $settings,
		private Flusher $flusher,
		private Queue $queue,
		private FileScanner $scanner,
		private Repository $history
	) {}

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::FLUSH, array( $this, 'run_flush' ) );
		add_action( self::SCAN, array( $this, 'run_scan' ) );
		add_action( self::PRUNE, array( $this, 'run_prune' ) );
	}

	public function schedules( array $schedules ): array {
		$schedules['purgekit_3h']     = array(
			'interval' => 3 * HOUR_IN_SECONDS,
			'display'  => 'Every 3 hours',
		);
		$schedules['purgekit_6h']     = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => 'Every 6 hours',
		);
		$schedules['purgekit_12h']    = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => 'Every 12 hours',
		);
		$schedules['purgekit_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Once weekly',
		);
		return $schedules;
	}

	/** Make scheduled events match current settings. Safe to call repeatedly. */
	public function sync(): void {
		$this->ensure( self::SCAN, 'hourly' );
		$this->ensure( self::PRUNE, 'daily' );
		if ( 'scheduled' === $this->settings->mode() ) {
			$this->ensure( self::FLUSH, self::RECURRENCE[ $this->settings->schedule() ] );
		} else {
			wp_clear_scheduled_hook( self::FLUSH );
		}
	}

	public function run_flush(): void {
		$this->flusher->flush( 'schedule', ! $this->settings->skipIfUnchanged() );
	}

	public function run_scan(): void {
		if ( $this->scanner->scan() ) {
			$this->queue->add( Change::siteWide( 'files' ) );
		}
	}

	public function run_prune(): void {
		$this->history->prune( (int) apply_filters( 'purgekit_history_days', 90 ) );
	}

	private function ensure( string $hook, string $recurrence ): void {
		$event = wp_get_scheduled_event( $hook );
		if ( $event && $event->schedule === $recurrence ) {
			return;
		}
		if ( $event ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
	}
}
