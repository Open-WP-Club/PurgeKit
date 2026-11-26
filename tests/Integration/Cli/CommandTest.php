<?php
namespace PurgeKit\Tests\Integration\Cli;

require_once __DIR__ . '/WpCliStub.php';

use PurgeKit\Cli\Command;
use PurgeKit\Core\Change;
use PurgeKit\Plugin;
use PurgeKit\Services;

final class CommandTest extends \WP_UnitTestCase {
	private Services $svc;
	private Command $cmd;

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::reset();
		$this->svc = Plugin::services();
		$this->svc->history->install();
		$this->svc->queue->drain();
		$this->svc->lock->release( 'flush' );
		$this->cmd = new Command( $this->svc );
	}

	private function out(): string {
		return implode( "\n", \WP_CLI::$lines );
	}

	public function test_flush_all_returns_zero_on_ok(): void {
		$this->cmd->flush( array(), array() );
		$this->assertNull( \WP_CLI::$halted );
		$this->assertStringContainsString( 'object-cache', $this->out() );
		$this->assertStringContainsString( 'Success', $this->out() );
	}

	public function test_dry_run_lists_layers_and_urls_and_purges_nothing(): void {
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . $this->svc->history->table() );
		$this->cmd->flush( array(), array( 'dry-run' => true ) );
		$this->assertStringContainsString( 'Would purge everything on:', $this->out() );
		$this->assertStringContainsString( 'object-cache', $this->out() );
		$this->assertStringContainsString( 'Dry run: nothing was purged.', $this->out() );
		$this->assertSame( 0, $this->svc->history->count() );

		\WP_CLI::reset();
		$this->cmd->flush( array( home_url( '/x/' ) ), array( 'dry-run' => true ) );
		$this->assertStringContainsString( 'Would purge 1 URL(s) on:', $this->out() );
		$this->assertStringContainsString( home_url( '/x/' ), $this->out() );
		$this->assertSame( 0, $this->svc->history->count() );
	}

	public function test_dry_run_cannot_be_combined_with_network(): void {
		$this->expectException( \RuntimeException::class );
		$this->cmd->flush( array(), array( 'dry-run' => true, 'network' => true ) );
	}

	public function test_flush_url_flushes_only_that_url(): void {
		$this->cmd->flush( array( home_url( '/x/' ) ), array() );
		$this->assertSame( 'cli', $this->svc->history->recent( 1, 0 )[0]['source'] );
		$this->assertSame( array( home_url( '/x/' ) ), $this->svc->history->recent( 1, 0 )[0]['urls'] );
	}

	public function test_flush_post_uses_permalink(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->cmd->flush( array(), array( 'post' => (string) $id ) );
		$this->assertContains( get_permalink( $id ), $this->svc->history->recent( 1, 0 )[0]['urls'] );
	}

	public function test_flush_unknown_post_is_error(): void {
		$this->expectException( \RuntimeException::class );
		$this->cmd->flush( array(), array( 'post' => '999999' ) );
	}

	public function test_flush_nonzero_on_partial(): void {
		add_filter(
			'purgekit_layers',
			static function ( $layers ) {
				$layers[] = new \PurgeKit\Layers\CallbackLayer( 'boom', 'Boom', 30, static fn() => true, static function () {
					throw new \RuntimeException( 'nope' );
				} );
				return $layers;
			}
		);
		$svc = Services::build();
		( new Command( $svc ) )->flush( array(), array() );
		$this->assertSame( 1, \WP_CLI::$halted );
		$this->assertStringContainsString( 'nope', $this->out() );
	}

	public function test_flush_while_locked_is_error(): void {
		$this->svc->lock->acquire( 'flush', 120 );
		$this->expectException( \RuntimeException::class );
		$this->cmd->flush( array(), array() );
	}

	public function test_layers_lists_every_layer(): void {
		$this->cmd->layers( array(), array() );
		foreach ( array( 'object-cache', 'cloudflare', 'varnish', 'wp-rocket', 'kinsta' ) as $id ) {
			$this->assertStringContainsString( $id, $this->out() );
		}
	}

	public function test_status_prints_pending_and_mode(): void {
		$this->svc->queue->add( Change::siteWide( 'x' ) );
		$this->cmd->status( array(), array() );
		$this->assertStringContainsString( 'Pending changes: 1', $this->out() );
		$this->assertStringContainsString( 'Mode: notify', $this->out() );
	}

	public function test_scan_first_run_reports_no_change(): void {
		$this->svc->store->delete( 'file_fingerprint' );
		$this->cmd->scan( array(), array() );
		$this->assertStringContainsString( 'No file changes', $this->out() );
	}
	public function test_warm_schedules_delayed_retries_instead_of_busy_looping(): void {
		$original = $this->svc->warmer;
		$store = new \PurgeKit\Tests\Fakes\FakeStore();
		$store->set( 'warm_pause_until', time() + 60 );
		$this->svc->warmer = new \PurgeKit\Warm\Warmer( new \PurgeKit\Tests\Fakes\FakeHttp(), $store, home_url( '/' ) );
		$this->svc->warmer->enqueue( \PurgeKit\Core\PurgePlan::everything() );
		wp_clear_scheduled_hook( 'purgekit_warm' );
		$this->cmd->warm( array(), array() );
		$this->assertSame( 1, $this->svc->warmer->pending() );
		$this->assertNotFalse( wp_next_scheduled( 'purgekit_warm' ) );
		$this->assertStringContainsString( 'scheduled in the background', $this->out() );
		$this->svc->warmer = $original;
	}

}
