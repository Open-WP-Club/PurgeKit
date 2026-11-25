<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Digest;
use PurgeKit\Core\HealthWatch;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\MetricSampler;
use PurgeKit\Core\Monitor;
use PurgeKit\Core\StatsCsv;
use PurgeKit\Inspector\Cacheability;
use PurgeKit\Inspector\SiteProbe;
use PurgeKit\Layers\AbstractLayer;
use PurgeKit\Layers\Metrics;
use PurgeKit\Layers\Registry;
use PurgeKit\Layers\Tier;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeStore;

final class MonitoringTest extends TestCase {
	private function check( string $status, bool $warn = false, string $message = 'm' ): array {
		return array(
			'label'   => 'Redis',
			'status'  => $status,
			'warn'    => $warn,
			'message' => $message,
		);
	}

	public function test_a_problem_is_reported_once_and_again_only_when_it_gets_worse(): void {
		$s = HealthWatch::step( array(), array( 'oc' => $this->check( 'ok', true, 'Memory is 95% full' ) ), 100 );
		$this->assertSame( array( 'oc' ), array_keys( $s['alerts'] ) );
		$this->assertSame( 'warn', $s['alerts']['oc']['issue'] );

		$s = HealthWatch::step( $s['state'], array( 'oc' => $this->check( 'ok', true ) ), 200 );
		$this->assertSame( array(), $s['alerts'], 'Same problem, no second alert.' );

		$s = HealthWatch::step( $s['state'], array( 'oc' => $this->check( 'failed', false, 'Does not answer' ) ), 300 );
		$this->assertSame( 'failed', $s['alerts']['oc']['issue'], 'A warning that became a failure is news.' );

		$s = HealthWatch::step( $s['state'], array( 'oc' => $this->check( 'ok', true ) ), 400 );
		$this->assertSame( array(), $s['alerts'], 'Easing from failed to a warning is not announced.' );

		$s = HealthWatch::step( $s['state'], array( 'oc' => $this->check( 'ok' ) ), 500 );
		$this->assertSame( array(), $s['state'], 'Recovered: forgotten.' );
		$again = HealthWatch::step( $s['state'], array( 'oc' => $this->check( 'failed' ) ), 600 );
		$this->assertCount( 1, $again['alerts'], 'A new episode alerts again.' );
	}

	public function test_a_skipped_check_changes_nothing(): void {
		$state = array( 'oc' => array( 'issue' => 'failed', 'since' => 1 ) );
		$s     = HealthWatch::step( $state, array( 'oc' => $this->check( 'skipped' ) ), 9 );
		$this->assertSame( $state, $s['state'] );
		$this->assertSame( array(), $s['alerts'] );
	}

	private function page( string $headers = '' ): HttpResponse {
		$h = array();
		if ( '' !== $headers ) {
			$h['age'] = '30';
		}
		return new HttpResponse( 200, '<html></html>', $h );
	}

	public function test_the_probe_counts_cached_pages_keeps_the_reasons_and_the_series(): void {
		$http  = new FakeHttp();
		$store = new FakeStore();
		// Page 1: cached (an Age header on the second visit). Page 2: private, so nothing can store it.
		$http->queue(
			$this->page(),
			$this->page( 'age' ),
			new HttpResponse( 200, '', array( 'cache-control' => 'private, no-store' ) ),
			new HttpResponse( 200, '', array( 'cache-control' => 'private, no-store' ) )
		);
		$probe = new SiteProbe( new Cacheability( $http, new Registry( array() ) ), $store );
		$r     = $probe->run( array( 'https://a.test/', 'https://a.test/cart/' ), 10000 );
		$this->assertSame( 2, $r['total'] );
		$this->assertSame( 1, $r['cached'] );
		$this->assertSame( 50.0, $r['percent'] );
		$this->assertSame( Cacheability::CACHED, $r['pages'][0]['verdict'] );
		$this->assertSame( Cacheability::BLOCKED, $r['pages'][1]['verdict'] );
		$this->assertNotEmpty( $r['pages'][1]['reasons'] );
		$this->assertNull( $probe->run( array( 'https://a.test/' ), 10100 ), 'Too soon.' );
		$this->assertNotNull( $probe->run( array( 'https://a.test/' ), 10100, true ), 'Forced runs always go.' );
		$this->assertSame( 10100, $probe->latest()['at'] );
		$this->assertCount( 1, $probe->series(), 'Points are at least 25 minutes apart, even for a forced run.' );
		$probe->run( array( 'https://a.test/' ), 12000, true );
		$this->assertCount( 2, $probe->series() );
	}

	public function test_a_page_that_cannot_be_fetched_is_left_out_of_the_percentage(): void {
		$http  = new FakeHttp();
		$http->queue( new \PurgeKit\Core\HttpException( 'timeout' ) );
		$probe = new SiteProbe( new Cacheability( $http, new Registry( array() ) ), new FakeStore() );
		$r     = $probe->run( array( 'https://a.test/' ), 1 );
		$this->assertSame( 0, $r['total'] );
		$this->assertSame( 'error', $r['pages'][0]['verdict'] );
		$this->assertSame( array(), $probe->series() );
	}

	private function layer( string $status, bool $warn ): AbstractLayer&Metrics {
		return new class( $status, $warn ) extends AbstractLayer implements Metrics {
			public function __construct( private string $status, private bool $warn ) {}
			public function id(): string {
				return 'oc';
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
			public function purgeUrls( array $urls ): LayerResult {
				return $this->ok();
			}
			public function purgeAll(): LayerResult {
				return $this->ok();
			}
			public function metrics(): array {
				return array( array( 'label' => 'Keys', 'value' => '1', 'number' => 1 ) );
			}
			public function test(): LayerResult {
				return new LayerResult( 'oc', $this->status, 'Redis is full', 0, $this->warn );
			}
		};
	}

	public function test_the_hourly_job_samples_alerts_once_and_probes(): void {
		$store  = new FakeStore();
		$sent   = array();
		$http   = new FakeHttp();
		$probe  = new SiteProbe( new Cacheability( $http, new Registry( array() ) ), $store );
		$m      = new Monitor( new MetricSampler( new Registry( array( $this->layer( 'ok', true ) ) ), $store ), $store, $probe, static function ( array $a ) use ( &$sent ) {
			$sent[] = $a;
		}, static fn() => array( 'https://a.test/' ) );
		$first = $m->tick( 10000, true, true );
		$this->assertSame( 1, $first['sampled'] );
		$this->assertSame( 1, $first['alerts'] );
		$this->assertTrue( $first['probed'] );
		$second = $m->tick( 14000, true, true );
		$this->assertSame( 0, $second['alerts'], 'Already reported.' );
		$off = $m->tick( 20000, false, false );
		$this->assertSame( array( 0, false ), array( $off['alerts'], $off['probed'] ), 'Both can be switched off.' );
		$this->assertCount( 1, $sent );
		$this->assertSame( 'warn', $sent[0]['oc']['issue'] );
	}

	public function test_the_digest_lists_the_week_in_plain_text(): void {
		$d = Digest::build(
			'My Site',
			'https://a.test/',
			array( 'flushes' => 12, 'targeted' => 9, 'full' => 3, 'problems' => 2, 'urls' => 40 ),
			array(
				'cf'  => array( 'purges' => 10, 'failed' => 2, 'success_rate' => 83, 'avg_ms' => 300 ),
				'old' => array( 'purges' => 0, 'failed' => 0, 'success_rate' => null, 'avg_ms' => 0 ),
			),
			array( 'cf' => 'Cloudflare' ),
			array( 'cached' => 3, 'total' => 4, 'percent' => 75, 'pages' => array( array( 'url' => 'https://a.test/cart/', 'verdict' => 'blocked' ), array( 'url' => 'https://a.test/', 'verdict' => 'cached' ) ) ),
			7
		);
		$this->assertStringContainsString( '12 flushes', $d['body'] );
		$this->assertStringContainsString( '- Cloudflare: 10 purges, 2 failed (83% worked), 300 ms on average', $d['body'] );
		$this->assertStringNotContainsString( 'old', $d['body'] );
		$this->assertStringContainsString( '3 of 4 checked (75%)', $d['body'] );
		$this->assertStringContainsString( 'Not cached: https://a.test/cart/', $d['body'] );
		$this->assertStringNotContainsString( 'Not cached: https://a.test/' . "\n", $d['body'] . "\n" );
		$this->assertSame( 'weekly_digest', $d['event']['event'] );
		$this->assertStringContainsString( '[My Site]', $d['subject'] );
	}

	public function test_the_stats_csv_is_quoted_and_formula_safe(): void {
		$csv = ( new StatsCsv() )->build( array( array( 'label' => '=HYPERLINK("x")', 'tier_label' => 'CDN', 'state' => 'active', 'purges' => 3, 'failed' => 1, 'success_rate' => 75, 'avg_ms' => 12, 'hit_rate' => null ) ) );
		$lines = explode( "\r\n", trim( $csv ) );
		$this->assertCount( 2, $lines );
		$this->assertStringStartsWith( '"layer","group"', $lines[0] );
		$this->assertStringStartsWith( '"\'=HYPERLINK(""x"")","CDN","active","3","1","75"', $lines[1] );
	}
}
