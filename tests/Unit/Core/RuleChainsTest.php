<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\Executor;
use PurgeKit\Core\Flusher;
use PurgeKit\Core\Planner;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Queue;
use PurgeKit\Core\RuleSet;
use PurgeKit\Core\RuleStore;
use PurgeKit\Core\Run;
use PurgeKit\Core\UrlExpander;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeHistory;
use PurgeKit\Tests\Fakes\FakeLayer;
use PurgeKit\Tests\Fakes\FakeLock;
use PurgeKit\Tests\Fakes\FakeStore;

/** Rule chains (delay, only after a failure), retrying layers and tag purging. */
final class RuleChainsTest extends TestCase {
	private Queue $queue;
	private FakeHistory $history;
	private FakeLayer $plugin;
	private FakeLayer $cdn;
	/** @var array<int,array> */
	public array $deferred = array();

	private function flusher( array $rules, string $plugin_status = 'ok' ): Flusher {
		$expander      = new class() implements UrlExpander {
			public function expand( Change $change ): array {
				return array();
			}
		};
		$this->queue   = new Queue( new FakeStore() );
		$this->history = new FakeHistory();
		$this->plugin  = new FakeLayer( 'plugin', 20, true, null, $plugin_status );
		$this->cdn     = new FakeLayer( 'cdn', 50 );
		$store         = new RuleStore( new FakeStore() );
		$store->save( RuleSet::fromArray( $rules ) );
		return new Flusher(
			$this->queue,
			new Planner( $expander, 'https://site.test' ),
			new Executor( new Registry( array( $this->plugin, $this->cdn ) ), new FakeClock() ),
			new FakeLock(),
			$this->history,
			static function () {},
			null,
			$store,
			null,
			function ( PurgePlan $plan, ?array $layers, array $rules, int $delay ): bool {
				$this->deferred[] = compact( 'plan', 'layers', 'rules', 'delay' );
				return true;
			}
		);
	}

	private function rules(): array {
		return array(
			array( 'name' => 'Plugin first', 'on' => array( 'post' ), 'layers' => array( 'plugin' ), 'action' => 'default' ),
		);
	}

	public function test_a_delayed_rule_is_handed_over_and_not_run_now(): void {
		$f = $this->flusher( array( array( 'name' => 'CDN later', 'layers' => array( 'cdn' ), 'delay' => 30 ) ) );
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/a' ) ) );
		$run = $f->flush( 'auto' );
		$this->assertSame( array(), $this->cdn->calls );
		$this->assertSame( 1, $run->deferred );
		$this->assertSame( 30, $this->deferred[0]['delay'] );
		$this->assertSame( array( 'cdn' ), $this->deferred[0]['layers'] );
		$this->assertSame( Run::SKIPPED, $run->status );
	}

	public function test_the_delay_is_capped_and_cleaned(): void {
		$set = RuleSet::fromArray( array( array( 'name' => 'x', 'delay' => 999999, 'when' => 'nonsense' ) ) );
		$this->assertSame( 3600, $set->rules()[0]->delay );
		$this->assertSame( 'always', $set->rules()[0]->when );
	}

	public function test_a_fallback_rule_runs_only_when_something_failed(): void {
		$rules = array(
			array( 'name' => 'Plugin', 'on' => array( 'post' ), 'layers' => array( 'plugin' ) ),
			array( 'name' => 'Fallback', 'on' => array( 'term' ), 'layers' => array( 'cdn' ), 'when' => 'on_failure' ),
		);
		$f = $this->flusher( $rules );
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/a' ) ) );
		$this->queue->add( Change::forUrls( 'term', array( 'https://site.test/t' ) ) );
		$f->flush( 'auto' );
		$this->assertSame( array(), $this->cdn->calls, 'The plugin purge worked, so the fallback stays quiet.' );

		$f = $this->flusher( $rules, 'failed' );
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/a' ) ) );
		$this->queue->add( Change::forUrls( 'term', array( 'https://site.test/t' ) ) );
		$run = $f->flush( 'auto' );
		$this->assertCount( 1, $this->cdn->calls );
		$this->assertSame( array( 'Plugin', 'Fallback' ), $run->rules );
	}

	public function test_retry_runs_only_the_chosen_layers_for_the_same_urls(): void {
		$f = $this->flusher( array() );
		$f->flushLayers( 'retry', array( 'cdn' ), array( 'https://site.test/a' ) );
		$this->assertSame( array(), $this->plugin->calls );
		$this->assertSame( array( array( 'urls', array( 'https://site.test/a' ) ) ), $this->cdn->calls );
		$f->flushLayers( 'retry', array( 'cdn' ) );
		$this->assertSame( 'all', $this->cdn->calls[1][0] );
		$this->assertSame( 'retry', $this->history->rows[0][0] );
	}

	public function test_a_deferred_job_runs_as_its_own_flush(): void {
		$f   = $this->flusher( array() );
		$run = $f->runDeferred(
			array(
				'all'    => false,
				'urls'   => array( 'https://site.test/a' ),
				'tags'   => array(),
				'layers' => array( 'cdn' ),
				'rules'  => array( 'CDN later' ),
			)
		);
		$this->assertSame( Run::OK, $run->status );
		$this->assertSame( 'delayed', $this->history->rows[0][0] );
		$this->assertSame( array(), $this->plugin->calls );
	}
}
