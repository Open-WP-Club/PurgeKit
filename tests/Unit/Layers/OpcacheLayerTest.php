<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\Opcache;
use PurgeKit\Layers\OpcacheLayer;
use PurgeKit\Layers\Tier;
use PurgeKit\Tests\Fakes\FakeStore;

final class OpcacheLayerTest extends TestCase {
	private function opcache( ?array $status, array $config = array(), bool $reset = true ): Opcache {
		return new class( $status, $config, $reset ) implements Opcache {
			public int $resets = 0;
			public function __construct( private ?array $status, private array $config, private bool $reset ) {}
			public function status(): ?array {
				return $this->status;
			}
			public function config(): array {
				return $this->config;
			}
			public function reset(): bool {
				++$this->resets;
				return $this->reset;
			}
		};
	}

	private function status( array $over = array() ): array {
		return array_replace_recursive(
			array(
				'opcache_enabled'    => true,
				'memory_usage'       => array(
					'used_memory'               => 50,
					'free_memory'               => 50,
					'wasted_memory'             => 0,
					'current_wasted_percentage' => 0,
				),
				'opcache_statistics' => array(
					'num_cached_scripts' => 120,
					'opcache_hit_rate'   => 99.5,
					'oom_restarts'       => 0,
				),
			),
			$over
		);
	}

	private function layer( Opcache $o, bool $on = true ): OpcacheLayer {
		$store = new FakeStore();
		$store->set( 'opcache_enabled', $on );
		return new OpcacheLayer( $store, $o, false );
	}

	public function test_is_off_until_switched_on_and_sits_below_the_object_cache(): void {
		$this->assertFalse( $this->layer( $this->opcache( $this->status() ), false )->detect() );
		$on = $this->layer( $this->opcache( $this->status() ) );
		$this->assertTrue( $on->detect() );
		$this->assertLessThan( Tier::OBJECT, $on->tier() );
		$this->assertSame( 'PHP code cache', Tier::label( $on->tier() ) );
	}

	public function test_a_single_page_purge_never_resets_opcache(): void {
		$o = $this->opcache( $this->status() );
		$r = $this->layer( $o )->purgeUrls( array( 'https://a.test/x' ) );
		$this->assertSame( LayerResult::SKIPPED, $r->status );
		$this->assertSame( 0, $o->resets );
	}

	public function test_purge_all_resets_and_reports_the_script_count(): void {
		$o = $this->opcache( $this->status() );
		$r = $this->layer( $o )->purgeAll();
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertSame( 1, $o->resets );
		$this->assertStringContainsString( '120 cached scripts', $r->message );
	}

	public function test_purge_all_fails_clearly_when_opcache_is_not_active_here(): void {
		$o = $this->opcache( null );
		$r = $this->layer( $o )->purgeAll();
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertStringContainsString( 'not active', $r->message );
		$this->assertSame( 0, $o->resets );
	}

	public function test_the_command_line_skips_instead_of_failing(): void {
		$store = new FakeStore();
		$store->set( 'opcache_enabled', true );
		$r = ( new OpcacheLayer( $store, $this->opcache( null ), true ) )->purgeAll();
		$this->assertSame( LayerResult::SKIPPED, $r->status );
		$this->assertStringContainsString( 'command line', $r->message );
	}

	public function test_metrics_give_numbers_and_nothing_when_unreachable(): void {
		$m = array_column( $this->layer( $this->opcache( $this->status() ) )->metrics(), 'value', 'label' );
		$this->assertSame( '99.5%', $m['Hit rate'] );
		$this->assertStringStartsWith( '120', $m['Scripts cached'] );
		$this->assertStringContainsString( '(50%)', $m['Memory'] );
		$by = array_column( $this->layer( $this->opcache( $this->status() ) )->metrics(), null, 'label' );
		$this->assertSame( 'quality', $by['Hit rate']['meter'] );
		$this->assertSame( 'usage', $by['Memory']['meter'] );
		$this->assertEqualsWithDelta( 50.0, $by['Memory']['percent'], 0.01 );
		$this->assertSame( array(), $this->layer( $this->opcache( null ) )->metrics() );
	}

	public function test_a_full_cache_is_flagged_as_a_warning(): void {
		$full = $this->layer( $this->opcache( $this->status( array( 'memory_usage' => array( 'used_memory' => 99, 'free_memory' => 1, 'wasted_memory' => 0, 'current_wasted_percentage' => 0 ) ) ) ) )->test();
		$this->assertSame( LayerResult::OK, $full->status );
		$this->assertTrue( $full->warn );
		$this->assertFalse( $this->layer( $this->opcache( $this->status() ) )->test()->warn );
	}

	public function test_purge_all_fails_when_php_refuses_the_reset(): void {
		$r = $this->layer( $this->opcache( $this->status(), array(), false ) )->purgeAll();
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertStringContainsString( 'restrict_api', $r->message );
	}

	public function test_check_reports_health(): void {
		$r = $this->layer( $this->opcache( $this->status() ) )->test();
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertStringContainsString( '120 scripts cached', $r->message );
		$this->assertStringContainsString( 'hit rate 99.5%', $r->message );
		$this->assertStringContainsString( 'memory 50% used', $r->message );
	}

	public function test_check_warns_about_a_full_cache_waste_and_unchecked_timestamps(): void {
		$full = $this->status(
			array(
				'memory_usage'       => array(
					'used_memory'               => 99,
					'free_memory'               => 1,
					'current_wasted_percentage' => 15,
				),
				'opcache_statistics' => array( 'oom_restarts' => 2 ),
			)
		);
		$r    = $this->layer( $this->opcache( $full, array( 'validate_timestamps' => '0', 'restrict_api' => '/var/www' ) ) )->test();
		$this->assertStringContainsString( 'cache is full', $r->message );
		$this->assertStringContainsString( '15% of the memory is wasted', $r->message );
		$this->assertStringContainsString( 'updated code only goes live after a reset', $r->message );
		$this->assertStringContainsString( 'restrict_api', $r->message );
	}

	public function test_check_when_inactive_is_skipped_not_failed(): void {
		$this->assertSame( LayerResult::SKIPPED, $this->layer( $this->opcache( null ) )->test()->status );
	}
}
