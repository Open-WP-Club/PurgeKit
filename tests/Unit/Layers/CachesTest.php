<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\Apcu;
use PurgeKit\Layers\ApcuLayer;
use PurgeKit\Layers\Catalog;
use PurgeKit\Layers\Tier;
use PurgeKit\Layers\Transients;
use PurgeKit\Layers\TransientsLayer;
use PurgeKit\Tests\Fakes\FakeStore;

require_once __DIR__ . '/../../Fakes/AutoptimizeStub.php';
require_once __DIR__ . '/../../Fakes/PerfmattersStub.php';

final class CachesTest extends TestCase {
	private function apcu( ?array $status, bool $clears = true ): Apcu {
		return new class( $status, $clears ) implements Apcu {
			public int $clears = 0;
			public function __construct( private ?array $status, private bool $ok ) {}
			public function status(): ?array {
				return $this->status;
			}
			public function clear(): bool {
				++$this->clears;
				return $this->ok;
			}
		};
	}

	private function store( string $key, bool $on ): FakeStore {
		$s = new FakeStore();
		$s->set( $key, $on );
		return $s;
	}

	public function test_apcu_is_opt_in_and_only_cleared_for_a_full_purge(): void {
		$a     = $this->apcu(
			array(
				'entries'   => 40,
				'hits'      => 90,
				'misses'    => 10,
				'mem_used'  => 50,
				'mem_total' => 100,
			)
		);
		$layer = new ApcuLayer( $this->store( 'apcu_enabled', false ), $a );
		$this->assertFalse( $layer->detect() );
		$layer = new ApcuLayer( $this->store( 'apcu_enabled', true ), $a );
		$this->assertTrue( $layer->detect() );
		$this->assertLessThan( Tier::OBJECT, $layer->tier() );

		$this->assertSame( LayerResult::SKIPPED, $layer->purgeUrls( array( 'https://a.test/' ) )->status );
		$this->assertSame( 0, $a->clears );

		$r = $layer->purgeAll();
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertStringContainsString( 'Cleared 40 entries', $r->message );
		$this->assertSame( 1, $a->clears );
		$this->assertStringContainsString( 'hit rate 90.0%', $layer->test()->message );
		$this->assertStringContainsString( 'memory 50% used', $layer->test()->message );
	}

	public function test_apcu_and_transients_show_their_numbers(): void {
		$a = new ApcuLayer( $this->store( 'apcu_enabled', true ), $this->apcu( array( 'entries' => 1500, 'hits' => 90, 'misses' => 10, 'mem_used' => 50, 'mem_total' => 100 ) ), false );
		$m = array_column( $a->metrics(), 'value', 'label' );
		$this->assertSame( '1,500', $m['Entries'] );
		$this->assertStringStartsWith( '90.0%', $m['Hit rate'] );
		$this->assertSame( array(), ( new ApcuLayer( $this->store( 'apcu_enabled', true ), $this->apcu( null ), false ) )->metrics() );

		$t = array_column( ( new TransientsLayer( $this->store( 'transients_enabled', true ), $this->transients( false, 40, 3 ) ) )->metrics(), 'value', 'label' );
		$this->assertSame( array( 'In the database' => '40', 'Expired' => '3' ), $t );
		$ext = ( new TransientsLayer( $this->store( 'transients_enabled', true ), $this->transients( true ) ) )->metrics();
		$this->assertSame( 'Stored in', $ext[0]['label'] );
	}

	public function test_apcu_fails_clearly_when_inactive_or_refusing(): void {
		$off = new ApcuLayer( $this->store( 'apcu_enabled', true ), $this->apcu( null ), false );
		$this->assertSame( LayerResult::FAILED, $off->purgeAll()->status );
		$this->assertSame( LayerResult::SKIPPED, $off->test()->status );
		$cli = new ApcuLayer( $this->store( 'apcu_enabled', true ), $this->apcu( null ), true );
		$this->assertSame( LayerResult::SKIPPED, $cli->purgeAll()->status, 'The command line cannot reach the web APCu, which is not a failure.' );

		$status = array(
			'entries'   => 1,
			'hits'      => 0,
			'misses'    => 0,
			'mem_used'  => 99,
			'mem_total' => 100,
		);
		$refuse = new ApcuLayer( $this->store( 'apcu_enabled', true ), $this->apcu( $status, false ), false );
		$this->assertSame( LayerResult::FAILED, $refuse->purgeAll()->status );
		$this->assertStringContainsString( 'almost full', $refuse->test()->message );
		$this->assertTrue( $refuse->test()->warn );
	}

	private function transients( bool $external, int $total = 5, int $expired = 2 ): Transients {
		return new class( $external, $total, $expired ) implements Transients {
			public int $deletes = 0;
			public function __construct( private bool $external, private int $total, private int $expired ) {}
			public function usesExternalCache(): bool {
				return $this->external;
			}
			public function count(): array {
				return array(
					'total'   => $this->total,
					'expired' => $this->expired,
				);
			}
			public function deleteAll(): int {
				++$this->deletes;
				return $this->total;
			}
		};
	}

	public function test_transients_are_deleted_only_on_a_full_purge_and_only_when_enabled(): void {
		$t     = $this->transients( false );
		$layer = new TransientsLayer( $this->store( 'transients_enabled', true ), $t );
		$this->assertTrue( $layer->detect() );
		$this->assertFalse( ( new TransientsLayer( $this->store( 'transients_enabled', false ), $t ) )->detect() );

		$this->assertSame( LayerResult::SKIPPED, $layer->purgeUrls( array( 'https://a.test/' ) )->status );
		$this->assertSame( 0, $t->deletes );
		$this->assertStringContainsString( 'Deleted 5 transients', $layer->purgeAll()->message );
		$this->assertStringContainsString( '5 transients in the database, 2 of them expired', $layer->test()->message );
	}

	public function test_transients_in_an_object_cache_are_left_alone(): void {
		$t     = $this->transients( true );
		$layer = new TransientsLayer( $this->store( 'transients_enabled', true ), $t );
		$r     = $layer->purgeAll();
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertSame( 0, $t->deletes );
		$this->assertStringContainsString( 'object cache', $r->message );
	}

	/** @return array<string,\PurgeKit\Layers\CallbackLayer> */
	private function assets( bool $enabled ): array {
		$out = array();
		foreach ( Catalog::assetLayers( static fn() => $enabled ) as $layer ) {
			$out[ $layer->id() ] = $layer;
		}
		return $out;
	}

	public function test_asset_layers_need_the_plugin_and_the_switch(): void {
		$on  = $this->assets( true );
		$off = $this->assets( false );
		$this->assertSame( array( 'elementor-files', 'divi-static', 'beaver-builder-assets', 'autoptimize', 'perfmatters', 'oxygen-universal-css' ), array_keys( $on ) );
		$this->assertTrue( $on['autoptimize']->detect(), 'The stand-in class is loaded.' );
		$this->assertFalse( $on['elementor-files']->detect(), 'Elementor is not installed here.' );
		$this->assertFalse( $off['autoptimize']->detect(), 'Switched off.' );
	}

	public function test_asset_layers_are_cleared_for_a_full_purge_never_for_a_single_page(): void {
		$layer = $this->assets( true )['autoptimize'];
		\autoptimizeCache::$cleared = 0;
		$this->assertSame( LayerResult::SKIPPED, $layer->purgeUrls( array( 'https://a.test/' ) )->status );
		$this->assertSame( 0, \autoptimizeCache::$cleared );
		$this->assertSame( LayerResult::OK, $layer->purgeAll()->status );
		$this->assertSame( 1, \autoptimizeCache::$cleared );
		$this->assertSame( Tier::PLUGIN, $layer->tier() );
	}

	public function test_perfmatters_clears_used_css_and_minified_css_on_a_full_purge_only(): void {
		$layer = $this->assets( true )['perfmatters'];
		\Perfmatters\CSS::$cleared    = 0;
		\Perfmatters\Minify::$cleared = array();
		$this->assertTrue( $layer->detect() );
		$this->assertFalse( $this->assets( false )['perfmatters']->detect(), 'Switched off.' );
		$this->assertSame( LayerResult::SKIPPED, $layer->purgeUrls( array( 'https://a.test/' ) )->status );
		$this->assertSame( 0, \Perfmatters\CSS::$cleared );
		$this->assertSame( LayerResult::OK, $layer->purgeAll()->status );
		$this->assertSame( 1, \Perfmatters\CSS::$cleared );
		$this->assertSame( array( 'css' ), \Perfmatters\Minify::$cleared );
	}

	public function test_oxygen_regenerates_its_universal_css_and_reports_a_refusal(): void {
		$layer = $this->assets( true )['oxygen-universal-css'];
		$GLOBALS['purgekit_oxygen_calls']  = 0;
		$GLOBALS['purgekit_oxygen_result'] = true;
		$this->assertTrue( $layer->detect() );
		$this->assertSame( LayerResult::OK, $layer->purgeAll()->status );
		$this->assertSame( 1, $GLOBALS['purgekit_oxygen_calls'] );
		$GLOBALS['purgekit_oxygen_result'] = false;
		$this->expectException( \RuntimeException::class ); // The Executor turns this into a failed result.
		$this->expectExceptionMessage( 'did not regenerate' );
		try {
			$layer->purgeAll();
		} finally {
			unset( $GLOBALS['purgekit_oxygen_result'] );
		}
	}
}
