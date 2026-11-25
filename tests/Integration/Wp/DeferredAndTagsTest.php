<?php
namespace PurgeKit\Tests\Integration\Wp;

use PurgeKit\Core\Change;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Detect\PostUrlExpander;
use PurgeKit\Detect\TagHeaders;
use PurgeKit\Plugin;
use PurgeKit\Services;
use PurgeKit\Wp\DeferredRunner;

final class DeferredAndTagsTest extends \WP_UnitTestCase {
	private Services $svc;

	public function set_up(): void {
		parent::set_up();
		$this->svc = Services::build();
		$this->svc->history->install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . $this->svc->history->table() );
		$this->svc->lock->release( 'flush' );
		$this->svc->store->delete( 'deferred_jobs' );
		wp_clear_scheduled_hook( DeferredRunner::HOOK );
	}

	public function tear_down(): void {
		$this->svc->store->delete( 'deferred_jobs' );
		$this->svc->store->delete( 'cache_tags' );
		$this->svc->store->delete( 'asset_version' );
		remove_all_filters( 'style_loader_src' );
		remove_all_filters( 'script_loader_src' );
		parent::tear_down();
	}

	public function test_a_postponed_job_is_scheduled_stored_and_runs_once_as_its_own_flush(): void {
		$runner = new DeferredRunner( $this->svc->deferred, $this->svc->flusher );
		$this->assertTrue( $runner->defer( PurgePlan::everything(), array( 'object-cache' ), array( 'CDN later' ), 30 ) );
		$job = $this->svc->deferred->all()[0];
		$this->assertGreaterThanOrEqual( time() + 29, wp_next_scheduled( DeferredRunner::HOOK, array( $job['id'] ) ) );

		$runner->run( $job['id'] );
		$row = $this->svc->history->recent( 1, 0 )[0];
		$this->assertSame( 'delayed', $row['source'] );
		$this->assertSame( array( 'CDN later' ), $row['trigger']['rules'] );
		$this->assertSame( array(), $this->svc->deferred->all() );
		$runner->run( $job['id'] ); // A second event for the same job does nothing.
		$this->assertSame( 1, $this->svc->history->count() );
	}

	public function test_a_job_that_finds_the_lock_taken_is_kept_and_tried_again_in_a_minute(): void {
		$runner = new DeferredRunner( $this->svc->deferred, $this->svc->flusher );
		$runner->defer( PurgePlan::everything(), null, array(), 1 );
		$id = $this->svc->deferred->all()[0]['id'];
		wp_clear_scheduled_hook( DeferredRunner::HOOK, array( $id ) ); // The original event; a retry must be a new one.
		$this->svc->lock->acquire( 'flush', 120 );
		$runner->run( $id );
		$this->svc->lock->release( 'flush' );
		$this->assertNotNull( $this->svc->deferred->find( $id ) );
		$this->assertGreaterThan( time() + 50, wp_next_scheduled( DeferredRunner::HOOK, array( $id ) ) );
	}

	public function test_register_puts_lost_events_back(): void {
		$item = $this->svc->deferred->add( time() + 120, PurgePlan::everything(), null, array() );
		$this->assertFalse( wp_next_scheduled( DeferredRunner::HOOK, array( $item['id'] ) ) );
		( new DeferredRunner( $this->svc->deferred, $this->svc->flusher ) )->register();
		$this->assertNotFalse( wp_next_scheduled( DeferredRunner::HOOK, array( $item['id'] ) ) );
	}

	public function test_a_rule_with_a_delay_defers_through_the_real_services(): void {
		$this->svc->rules->save( \PurgeKit\Core\RuleSet::fromArray( array( array( 'name' => 'Later', 'layers' => array( 'object-cache' ), 'delay' => 45 ) ) ) );
		$this->svc->queue->add( Change::forUrls( 'post', array( home_url( '/a/' ) ) ) );
		$run = $this->svc->flusher->flush( 'auto' );
		$this->assertSame( 1, $run->deferred );
		$this->assertCount( 1, $this->svc->deferred->all() );
		$this->svc->rules->save( \PurgeKit\Core\RuleSet::fromArray( array() ) );
	}

	public function test_a_post_change_names_its_tags_and_its_machine_urls(): void {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		$tags = ( new PostUrlExpander() )->tags( Change::forUrls( 'post', array(), array( 'post_id' => $post ) ) );
		$this->assertContains( "p-$post", $tags );
		$this->assertContains( 'home', $tags );
		$this->assertContains( "t-$cat", $tags );
		$this->assertContains( 'pt-post', $tags );
		$this->assertSame( array( 't-7', 'home' ), ( new PostUrlExpander() )->tags( Change::forUrls( 'term', array(), array( 'term_id' => 7 ) ) ) );

		$urls = ( new PostUrlExpander() )->expand( Change::forUrls( 'post', array(), array( 'post_id' => $post ) ) );
		$this->assertContains( home_url( "/wp-json/wp/v2/posts/$post" ), $urls );
		$this->assertContains( home_url( '/wp-json/wp/v2/posts' ), $urls );
		$this->assertContains( add_query_arg( 'amp', '1', get_permalink( $post ) ), $urls );
	}

	public function test_pages_carry_the_tags_a_purge_will_look_for(): void {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		$tags = new TagHeaders( $this->svc->settings );

		$this->go_to( get_permalink( $post ) );
		$this->assertContains( "p-$post", $tags->tags() );

		$this->go_to( get_category_link( $cat ) );
		$on_archive = $tags->tags();
		$this->assertContains( "t-$cat", $on_archive );
		$this->assertContains( "p-$post", $on_archive, 'A listing carries the tag of every post it shows.' );

		$this->go_to( home_url( '/' ) );
		$this->assertContains( 'home', $tags->tags() );
	}

	public function test_nothing_is_sent_while_cache_tags_are_off(): void {
		$sent = array();
		add_filter( 'purgekit_page_tags', static function ( $t ) use ( &$sent ) {
			$sent = $t;
			return $t;
		} );
		$this->go_to( home_url( '/' ) );
		( new TagHeaders( $this->svc->settings ) )->send();
		$this->assertSame( array(), $sent, 'The filter is not even reached.' );
		remove_all_filters( 'purgekit_page_tags' );
	}

	public function test_the_planner_only_adds_tags_when_asked(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->svc->settings->update( array( 'cache_tags' => true ) );
		$on = Services::build();
		$on->queue->drain();
		$on->queue->add( Change::forUrls( 'post', array(), array( 'post_id' => $post ) ) );
		$preview = $on->flusher->preview();
		$this->assertGreaterThan( 0, $preview['jobs'][0]['tags'] );
		$this->svc->store->delete( 'cache_tags' );
		$off = Services::build();
		$this->assertSame( 0, $off->flusher->preview()['jobs'][0]['tags'] ?? 0 );
		$off->queue->drain();
	}

	public function test_the_asset_version_is_added_to_own_files_only(): void {
		$this->svc->settings->bumpAssetVersion( 1700000000 );
		$method = new \ReflectionMethod( Plugin::class, 'asset_version' );
		$method->invoke( null, Services::build() );
		$own = apply_filters( 'style_loader_src', home_url( '/wp-content/themes/t/style.css?ver=1.0' ), 'x' );
		$this->assertStringContainsString( 'pkv=1700000000', $own );
		$this->assertStringContainsString( 'ver=1.0', $own );
		$this->assertSame( 'https://cdn.example.com/a.js', apply_filters( 'script_loader_src', 'https://cdn.example.com/a.js', 'y' ) );
		$this->assertStringContainsString( 'pkv=1700000000', apply_filters( 'script_loader_src', '/wp-includes/js/a.js', 'y' ) );
	}

	public function test_dry_run_previews_without_purging(): void {
		$all = $this->svc->flusher->previewTarget( null );
		$this->assertTrue( $all['all'] );
		$this->assertSame( 'object-cache', $all['jobs'][0]['layers'][0]['id'] );
		$one = $this->svc->flusher->previewTarget( array( home_url( '/a/' ), 'https://evil.test/' ) );
		$this->assertSame( array( home_url( '/a/' ) ), $one['urls'] );
		$this->assertSame( 0, $this->svc->history->count(), 'A dry run is not recorded.' );
	}
}
