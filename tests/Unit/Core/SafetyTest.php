<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\EnvironmentGuard;
use PurgeKit\Core\Executor;
use PurgeKit\Core\Health;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Planner;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Core\UrlExpander;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeLayer;

final class SafetyTest extends TestCase {
	public function test_production_with_the_recorded_host_is_not_paused(): void {
		$this->assertNull( ( new EnvironmentGuard( 'production', 'a.test', 'a.test', false ) )->reason() );
		$this->assertNull( ( new EnvironmentGuard( 'production', 'a.test', '', false ) )->reason(), 'Nothing recorded yet.' );
		$this->assertNull( ( new EnvironmentGuard( 'production', 'A.test', 'a.TEST', false ) )->reason(), 'Host case does not matter.' );
	}

	public function test_staging_environment_or_a_changed_host_pauses_external_layers_only(): void {
		$staging = new EnvironmentGuard( 'staging', 'a.test', 'a.test', false );
		$this->assertStringContainsString( "'staging'", (string) $staging->reason() );
		$this->assertNotNull( $staging->reasonFor( 50 ) );
		$this->assertNull( $staging->reasonFor( 40 ), 'Reverse proxies and below are on this server.' );

		$cloned = new EnvironmentGuard( 'production', 'staging.test', 'a.test', false );
		$this->assertStringContainsString( 'a.test to staging.test', (string) $cloned->reason() );
	}

	public function test_explicit_allow_lifts_the_pause(): void {
		$this->assertNull( ( new EnvironmentGuard( 'staging', 'x.test', 'a.test', true ) )->reasonFor( 50 ) );
	}

	public function test_executor_skips_left_out_layers_and_reports_them(): void {
		$obj = new FakeLayer( 'obj', 10 );
		$cdn = new FakeLayer( 'cdn', 50 );
		$run = ( new Executor( new Registry( array( $obj, $cdn ) ), new FakeClock(), static fn( $l ) => 'cdn' === $l->id() ? 'paused' : null ) )->run( PurgePlan::everything() );
		$this->assertSame( Run::OK, $run->status );
		$this->assertSame( array(), $cdn->calls );
		$this->assertCount( 1, $obj->calls );
		$this->assertSame( LayerResult::SKIPPED, $run->results[1]->status );
		$this->assertSame( 'paused', $run->results[1]->message );
	}

	public function test_a_run_where_every_layer_is_left_out_is_skipped_not_ok(): void {
		$cdn = new FakeLayer( 'cdn', 50 );
		$run = ( new Executor( new Registry( array( $cdn ) ), new FakeClock(), static fn() => 'paused' ) )->run( PurgePlan::everything() );
		$this->assertSame( Run::SKIPPED, $run->status );
		$this->assertSame( array(), $cdn->calls );
	}

	private function planner( array $exclude ): Planner {
		$expander = new class() implements UrlExpander {
			public function expand( Change $change ): array {
				return array();
			}
		};
		return new Planner( $expander, 'https://site.test', $exclude );
	}

	public function test_excluded_paths_are_not_purged_but_manual_and_rule_urls_are(): void {
		$p    = $this->planner( array( '/cart/*', '/checkout/', '/shop/?add-to-cart=*', '*.json' ) );
		$urls = array( 'https://site.test/cart/', 'https://site.test/cart/x', 'https://site.test/checkout/', 'https://site.test/checkout/thanks/', 'https://site.test/shop/?add-to-cart=5', 'https://site.test/a.json', 'https://site.test/keep/' );
		$this->assertSame( array( 'https://site.test/checkout/thanks/', 'https://site.test/keep/' ), $p->plan( array( Change::forUrls( 'post', $urls ) ) )->urls );
		$this->assertCount( 7, $p->plan( array( Change::forUrls( 'manual', $urls ) ) )->urls );
		$this->assertSame( array( 'https://site.test/cart/' ), $p->plan( array( Change::forUrls( 'rule', array( 'https://site.test/cart/' ) ) ) )->urls );
	}

	public function test_regex_characters_in_a_pattern_are_literal(): void {
		$p = $this->planner( array( '/a.b/(x)' ) );
		$this->assertSame( array( 'https://site.test/aXb/(x)' ), $p->plan( array( Change::forUrls( 'post', array( 'https://site.test/a.b/(x)', 'https://site.test/aXb/(x)' ) ) ) )->urls );
	}

	public function test_summarise_keeps_only_a_few_short_problems(): void {
		$results = array();
		foreach ( range( 1, 8 ) as $i ) {
			$results[] = new LayerResult( "l$i", LayerResult::FAILED, str_repeat( 'x', 500 ) );
		}
		$sum = Health::summarise( Run::fromResults( $results, PurgePlan::everything() ), 'cron', 1000 );
		$this->assertSame( 'failed', $sum['status'] );
		$this->assertCount( 5, $sum['problems'] );
		$this->assertSame( 200, strlen( $sum['problems'][0]['message'] ) );
		$this->assertSame( 1000, $sum['at'] );
	}

	public function test_health_checks(): void {
		$this->assertSame( Health::GOOD, Health::worst( Health::checks( null, null, 2 ) ) );
		$this->assertSame( Health::RECOMMENDED, Health::worst( Health::checks( null, null, 0 ) ) );
		$this->assertSame( Health::RECOMMENDED, Health::worst( Health::checks( null, 'it is staging', 1 ) ) );

		$failed = Health::checks(
			array(
				'status'   => 'failed',
				'problems' => array(
					array(
						'layer'   => 'cloudflare',
						'message' => 'bad token',
					),
				),
			),
			null,
			1
		);
		$this->assertSame( Health::CRITICAL, Health::worst( $failed ) );
		$this->assertStringContainsString( 'cloudflare (bad token)', $failed[0]['description'] );
		$this->assertSame( Health::RECOMMENDED, Health::worst( Health::checks( array( 'status' => 'partial' ), null, 1 ) ) );
		$this->assertSame( Health::GOOD, Health::worst( Health::checks( array( 'status' => 'ok' ), null, 1 ) ) );
	}
}
