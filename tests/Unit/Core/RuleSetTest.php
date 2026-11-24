<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\Executor;
use PurgeKit\Core\Flusher;
use PurgeKit\Core\Planner;
use PurgeKit\Core\Queue;
use PurgeKit\Core\Rule;
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

final class RuleSetTest extends TestCase {
	private function planner(): Planner {
		$expander = new class() implements UrlExpander {
			public function expand( Change $change ): array {
				return array();
			}
		};
		return new Planner( $expander, 'https://site.test' );
	}

	private function set( array ...$rules ): RuleSet {
		return RuleSet::fromArray( $rules );
	}

	public function test_sanitising_drops_bad_data(): void {
		$set = RuleSet::fromArray(
			array(
				'junk',
				array( 'name' => '' ),
				array(
					'name'   => 'Bad action',
					'action' => 'explode',
				),
				array(
					'name'       => 'Good',
					'on'         => array( 'post', 'nonsense', 5 ),
					'post_types' => array( 'product', 'Bad Type!' ),
					'layers'     => array( 'cloudflare', 'ghost' ),
					'action'     => 'all',
				),
			),
			array( 'cloudflare', 'varnish' )
		);
		$this->assertCount( 1, $set->rules() );
		$rule = $set->rules()[0];
		$this->assertSame( array( 'post' ), $rule->on );
		$this->assertSame( array( 'product' ), $rule->post_types );
		$this->assertSame( array( 'cloudflare' ), $rule->layers );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{4,16}$/', $rule->id );
	}

	public function test_round_trips_and_caps_rule_count(): void {
		$raw = array();
		for ( $i = 0; $i < 80; $i++ ) {
			$raw[] = array( 'name' => "r$i" );
		}
		$set = RuleSet::fromArray( $raw );
		$this->assertCount( RuleSet::MAX_RULES, $set->rules() );
		$this->assertEquals( $set->toArray(), RuleSet::fromArray( $set->toArray() )->toArray() );
	}

	public function test_duplicate_ids_are_regenerated(): void {
		$set = $this->set(
			array(
				'id'   => 'abcd',
				'name' => 'a',
			),
			array(
				'id'   => 'abcd',
				'name' => 'b',
			)
		);
		$this->assertNotSame( $set->rules()[0]->id, $set->rules()[1]->id );
	}

	public function test_unmatched_changes_are_planned_normally_on_all_layers(): void {
		$jobs = $this->set( array( 'name' => 'x', 'on' => array( 'term' ), 'action' => 'skip' ) )
			->route( array( Change::forUrls( 'post', array( 'https://site.test/a' ) ) ), $this->planner() );
		$this->assertCount( 1, $jobs );
		$this->assertNull( $jobs[0]['layers'] );
		$this->assertSame( array( 'https://site.test/a' ), $jobs[0]['plan']->urls );
	}

	public function test_first_matching_rule_wins_and_disabled_rules_are_ignored(): void {
		$set  = $this->set(
			array( 'name' => 'off', 'enabled' => false, 'on' => array( 'post' ), 'action' => 'skip' ),
			array( 'name' => 'first', 'on' => array( 'post' ), 'action' => 'home' ),
			array( 'name' => 'second', 'on' => array( 'post' ), 'action' => 'skip' )
		);
		$jobs = $set->route( array( Change::forUrls( 'post', array( 'https://site.test/a' ) ) ), $this->planner() );
		$this->assertSame( array( 'https://site.test/' ), $jobs[0]['plan']->urls );
	}

	public function test_post_type_filter_needs_the_context(): void {
		$set = $this->set( array( 'name' => 'shop', 'on' => array( 'post' ), 'post_types' => array( 'product' ), 'action' => 'skip' ) );
		$a   = Change::forUrls( 'post', array( 'https://site.test/p' ), array( 'post_type' => 'product' ) );
		$b   = Change::forUrls( 'post', array( 'https://site.test/q' ), array( 'post_type' => 'post' ) );
		$c   = Change::forUrls( 'post', array( 'https://site.test/r' ) );
		$jobs = $set->route( array( $a, $b, $c ), $this->planner() );
		$this->assertSame( array( 'https://site.test/q', 'https://site.test/r' ), $jobs[0]['plan']->urls );
	}

	public function test_everything_on_all_layers_swallows_other_jobs(): void {
		$set  = $this->set(
			array( 'name' => 'theme', 'on' => array( 'switch_theme' ), 'action' => 'all' ),
			array( 'name' => 'post', 'on' => array( 'post' ), 'layers' => array( 'a' ) )
		);
		$jobs = $set->route( array( Change::forUrls( 'post', array( 'https://site.test/a' ) ), Change::siteWide( 'switch_theme' ) ), $this->planner() );
		$this->assertCount( 1, $jobs );
		$this->assertTrue( $jobs[0]['plan']->all );
	}

	public function test_same_outcome_changes_share_a_job(): void {
		$set  = $this->set( array( 'name' => 'p', 'on' => array( 'post' ), 'layers' => array( 'b', 'a' ) ) );
		$jobs = $set->route(
			array( Change::forUrls( 'post', array( 'https://site.test/1' ) ), Change::forUrls( 'post', array( 'https://site.test/2' ) ) ),
			$this->planner()
		);
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( 'a', 'b' ), $jobs[0]['layers'] );
		$this->assertCount( 2, $jobs[0]['plan']->urls );
	}

	public function test_flusher_runs_rule_jobs_on_their_own_layers(): void {
		$store = new FakeStore();
		$rules = new RuleStore( $store );
		$rules->save( $this->set( array( 'name' => 'cdn only', 'on' => array( 'post' ), 'layers' => array( 'cdn' ), 'action' => 'all' ) ) );
		$obj   = new FakeLayer( 'obj', 10 );
		$cdn   = new FakeLayer( 'cdn', 50 );
		$queue = new Queue( new FakeStore() );
		$queue->add( Change::forUrls( 'post', array( 'https://site.test/a' ) ) );
		$queue->add( Change::forUrls( 'term', array( 'https://site.test/t' ) ) );
		$flusher = new Flusher( $queue, $this->planner(), new Executor( new Registry( array( $obj, $cdn ) ), new FakeClock() ), new FakeLock(), new FakeHistory(), static function () {}, null, $rules );

		$run = $flusher->flush( 'cron' );

		$this->assertSame( Run::OK, $run->status );
		// The unmatched term change takes the normal route, so the CDN also sees its URL.
		$this->assertSame( array( array( 'all', array() ), array( 'urls', array( 'https://site.test/t' ) ) ), $cdn->calls );
		$this->assertSame( array( array( 'urls', array( 'https://site.test/t' ) ) ), $obj->calls );
		$this->assertTrue( $run->plan->all );
	}

	public function test_flusher_with_only_skip_rules_is_skipped(): void {
		$rules = new RuleStore( new FakeStore() );
		$rules->save( $this->set( array( 'name' => 'quiet', 'on' => array( 'comment' ), 'action' => 'skip' ) ) );
		$layer = new FakeLayer( 'a', 10 );
		$queue = new Queue( new FakeStore() );
		$queue->add( Change::forUrls( 'comment', array( 'https://site.test/c' ) ) );
		$run = ( new Flusher( $queue, $this->planner(), new Executor( new Registry( array( $layer ) ), new FakeClock() ), new FakeLock(), new FakeHistory(), static function () {}, null, $rules ) )->flush( 'cron' );
		$this->assertSame( Run::SKIPPED, $run->status );
		$this->assertSame( array(), $layer->calls );
	}

	public function test_executor_only_filter(): void {
		$a = new FakeLayer( 'a', 10 );
		$b = new FakeLayer( 'b', 20 );
		( new Executor( new Registry( array( $a, $b ) ), new FakeClock() ) )->run( \PurgeKit\Core\PurgePlan::everything(), array( 'b' ) );
		$this->assertSame( array(), $a->calls );
		$this->assertCount( 1, $b->calls );
	}

	public function test_site_wide_changes_still_purge_everything_when_rules_exist(): void {
		$store = new FakeStore();
		$rules = new RuleStore( $store );
		$rules->save( $this->set( array( 'name' => 'posts only', 'on' => array( 'post' ), 'action' => 'skip' ) ) );
		$layer = new FakeLayer( 'a', 10 );
		$queue = new Queue( new FakeStore() );
		$queue->add( Change::siteWide( 'switch_theme' ) );
		$run = ( new Flusher( $queue, $this->planner(), new Executor( new Registry( array( $layer ) ), new FakeClock() ), new FakeLock(), new FakeHistory(), static function () {}, null, $rules ) )->flush( 'cron' );
		$this->assertSame( Run::OK, $run->status );
		$this->assertSame( array( array( 'all', array() ) ), $layer->calls );
	}

	public function test_bad_field_types_do_not_warn_or_get_saved(): void {
		$set = RuleSet::fromArray(
			array(
				array( 'name' => array( 'x' ) ),
				array(
					'name'    => 'ok',
					'enabled' => 'false',
				),
			)
		);
		$this->assertCount( 1, $set->rules() );
		$this->assertFalse( $set->rules()[0]->enabled );
	}

	public function test_route_detailed_names_rules_and_lists_skipped_changes(): void {
		$set = $this->set(
			array( 'name' => 'Posts', 'on' => array( 'post' ), 'action' => 'default', 'layers' => array( 'cdn' ) ),
			array( 'name' => 'Pages', 'on' => array( 'term' ), 'action' => 'default', 'layers' => array( 'cdn' ) ),
			array( 'name' => 'Quiet', 'on' => array( 'comment' ), 'action' => 'skip' )
		);
		$out = $set->routeDetailed(
			array(
				Change::forUrls( 'post', array( 'https://site.test/a' ) ),
				Change::forUrls( 'term', array( 'https://site.test/t' ) ),
				Change::forUrls( 'comment', array( 'https://site.test/c' ) ),
				Change::forUrls( 'option', array( 'https://site.test/o' ) ),
			),
			$this->planner()
		);
		$this->assertCount( 2, $out['jobs'] );
		$this->assertSame( array( 'Posts', 'Pages' ), $out['jobs'][0]['rules'] );
		$this->assertSame( array(), $out['jobs'][1]['rules'] );
		$this->assertSame( array( array( 'type' => 'comment', 'rule' => 'Quiet', 'urls' => array( 'https://site.test/c' ) ) ), $out['skipped'] );
	}

	public function test_route_keeps_its_old_shape(): void {
		$set  = $this->set( array( 'name' => 'x', 'action' => 'default' ) );
		$jobs = $set->route( array( Change::forUrls( 'post', array( 'https://site.test/a' ) ) ), $this->planner() );
		$this->assertSame( array( 'plan', 'layers' ), array_keys( $jobs[0] ) );
	}

	public function test_flush_layer_purges_everything_on_that_layer_only(): void {
		$obj     = new FakeLayer( 'obj', 10 );
		$cdn     = new FakeLayer( 'cdn', 50 );
		$history = new FakeHistory();
		$flusher = new Flusher( new Queue( new FakeStore() ), $this->planner(), new Executor( new Registry( array( $obj, $cdn ) ), new FakeClock() ), new FakeLock(), $history, static function () {} );

		$run = $flusher->flushLayer( 'admin-layer', 'cdn' );

		$this->assertSame( Run::OK, $run->status );
		$this->assertSame( array(), $obj->calls );
		$this->assertSame( array( array( 'all', array() ) ), $cdn->calls );
		$this->assertSame( 'admin-layer', $history->rows[0][0] );
		$this->assertSame( array( 'cdn' ), array_map( static fn( $r ) => $r->layer, $run->results ) );
	}
}
