<?php
namespace PurgeKit\Tests\Integration\Detect;

use PurgeKit\Core\Queue;
use PurgeKit\Detect\Hooks;
use PurgeKit\Wp\WpStore;

final class HooksTest extends \WP_UnitTestCase {
	private Queue $queue;

	public function set_up(): void {
		parent::set_up();
		$this->queue = new Queue( new WpStore() );
		$this->queue->drain();
		( new Hooks( $this->queue ) )->register();
	}

	public function test_publish_post_queues_url_change(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertGreaterThan( 0, $this->queue->count() );
		$changes = $this->queue->drain();
		$this->assertSame( 'post', $changes[0]->type );
		$this->assertSame( $id, $changes[0]->context['post_id'] );
		$this->assertFalse( $changes[0]->site_wide );
	}

	public function test_draft_post_makes_no_change(): void {
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( 0, $this->queue->count() );
	}

	public function test_autosave_makes_no_change(): void {
		$parent = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_status' => 'inherit', 'post_type' => 'revision', 'post_parent' => $parent, 'post_name' => $parent . '-autosave-v1' ) );
		$this->assertSame( 0, $this->queue->count() );
	}

	public function test_revision_makes_no_change(): void {
		$parent = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->queue->drain();
		wp_save_post_revision( $parent );
		$this->assertSame( 0, $this->queue->count() );
	}

	public function test_private_post_type_makes_no_change(): void {
		register_post_type( 'secret_thing', array( 'public' => false ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_type' => 'secret_thing' ) );
		$this->assertSame( 0, $this->queue->count() );
	}

	public function test_ignored_post_type_filter(): void {
		add_filter( 'purgekit_ignored_post_types', static fn( $t ) => array_merge( $t, array( 'page' ) ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_type' => 'page' ) );
		$this->assertSame( 0, $this->queue->count() );
	}

	public function test_unpublish_queues_change(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->queue->drain();
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertGreaterThan( 0, $this->queue->count() );
	}

	public function test_slug_change_queues_old_url(): void {
		$id  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'old-slug' ) );
		$old = get_permalink( $id );
		$this->queue->drain();
		wp_update_post( array( 'ID' => $id, 'post_name' => 'new-slug' ) );
		$urls = array();
		foreach ( $this->queue->drain() as $c ) {
			$urls = array_merge( $urls, $c->urls );
		}
		$this->assertContains( $old, $urls );
	}

	public function test_menu_update_queues_site_wide(): void {
		wp_update_nav_menu_object( 0, array( 'menu-name' => 'Test menu' ) );
		$changes = $this->queue->drain();
		$this->assertTrue( $changes[0]->site_wide );
	}

	public function test_watched_option_queues_site_wide_and_others_do_not(): void {
		update_option( 'some_random_option', 'x' );
		$this->assertSame( 0, $this->queue->count() );
		update_option( 'blogname', 'Changed' );
		$this->assertTrue( $this->queue->drain()[0]->site_wide );
	}

	public function test_unapproved_comment_makes_no_change(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->queue->drain();
		self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '0' ) );
		$this->assertSame( 0, $this->queue->count() );
	}

	public function test_approved_comment_queues_post_url_change(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->queue->drain();
		self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '1' ) );
		$this->assertSame( $post, $this->queue->drain()[0]->context['post_id'] );
	}

	public function test_term_edit_queues_url_change(): void {
		$term = self::factory()->category->create();
		$this->queue->drain();
		wp_update_term( $term, 'category', array( 'name' => 'Renamed' ) );
		$this->assertSame( $term, $this->queue->drain()[0]->context['term_id'] );
	}
}
