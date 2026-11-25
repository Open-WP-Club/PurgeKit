<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\MetricSampler;
use PurgeKit\Core\MetricSeries;
use PurgeKit\Layers\AbstractLayer;
use PurgeKit\Layers\Metrics;
use PurgeKit\Layers\Registry;
use PurgeKit\Layers\Tier;
use PurgeKit\Tests\Fakes\FakeStore;

final class MetricSeriesTest extends TestCase {
	public function test_only_percentages_and_counts_are_kept(): void {
		$v = MetricSeries::values(
			array(
				array( 'label' => 'Memory', 'value' => 'x', 'percent' => 63.456, 'meter' => 'usage' ),
				array( 'label' => 'Keys', 'value' => '1,200', 'number' => 1200 ),
				array( 'label' => 'Driver', 'value' => 'Redis' ),
			)
		);
		$this->assertSame( array( 'Memory' => 63.5, 'Keys' => 1200.0 ), $v );
	}

	public function test_a_layer_is_sampled_at_most_every_25_minutes(): void {
		list( $all, $added ) = MetricSeries::record( array(), 'redis', array( 'Keys' => 5.0 ), 10000 );
		$this->assertTrue( $added );
		list( $all, $added ) = MetricSeries::record( $all, 'redis', array( 'Keys' => 6.0 ), 10000 + 600 );
		$this->assertFalse( $added );
		list( $all, $added ) = MetricSeries::record( $all, 'redis', array( 'Keys' => 7.0 ), 10000 + 3600 );
		$this->assertTrue( $added );
		$this->assertSame( array( array( 10000, 5.0 ), array( 13600, 7.0 ) ), $all['redis']['series']['Keys'] );
	}

	public function test_the_history_is_capped_and_forgets_layers_that_are_gone(): void {
		$all = array();
		for ( $i = 0; $i < MetricSeries::MAX + 20; $i++ ) {
			list( $all ) = MetricSeries::record( $all, 'redis', array( 'Keys' => (float) $i ), $i * 3600 );
		}
		$points = $all['redis']['series']['Keys'];
		$this->assertCount( MetricSeries::MAX, $points );
		$this->assertSame( 20.0, $points[0][1], 'The oldest points go first.' );
		$this->assertSame( array(), MetricSeries::keep( $all, array( 'other' ) ) );
		$this->assertSame( array( 'redis' ), array_keys( MetricSeries::keep( $all, array( 'redis' ) ) ) );
		$this->assertSame( array(), MetricSeries::record( array(), 'x', array(), 1 )[0], 'Nothing to save, nothing saved.' );
	}

	private function layer( bool $throws = false ): AbstractLayer&Metrics {
		return new class( $throws ) extends AbstractLayer implements Metrics {
			public function __construct( private bool $throws ) {}
			public function id(): string {
				return 'fake';
			}
			public function label(): string {
				return 'Fake';
			}
			public function tier(): int {
				return Tier::OBJECT;
			}
			public function detect(): bool {
				return true;
			}
			public function purgeUrls( array $urls ): LayerResult {
				return $this->ok();
			}
			public function purgeAll(): LayerResult {
				return $this->ok();
			}
			public function metrics(): array {
				if ( $this->throws ) {
					throw new \RuntimeException( 'down' );
				}
				return array( array( 'label' => 'Keys', 'value' => '1', 'number' => 1 ) );
			}
		};
	}

	public function test_the_sampler_saves_a_point_per_due_layer_and_survives_a_broken_one(): void {
		$store   = new FakeStore();
		$sampler = new MetricSampler( new Registry( array( $this->layer() ) ), $store );
		$this->assertSame( 1, $sampler->sample( 5000 ) );
		$this->assertSame( 0, $sampler->sample( 5100 ), 'Too soon.' );
		$this->assertSame( array( 'fake' => array( 'Keys' => array( array( 5000, 1.0 ) ) ) ), $sampler->series() );
		$broken = new MetricSampler( new Registry( array( $this->layer( true ) ) ), new FakeStore() );
		$this->assertSame( 0, $broken->sample( 5000 ) );
	}
}
