<?php
namespace PurgeKit\Tests\Integration\Detect;

use PurgeKit\Core\Change;
use PurgeKit\Detect\Dependencies;
use PurgeKit\Plugin;

final class DependencyChangesTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::services()->queue->drain();
		$this->set_permalink_structure( '/%year%/%postname%/' );
	}

	private function page( string $content, string $type = 'page' ): int {
		return self::factory()->post->create( array( 'post_status' => 'publish', 'post_type' => $type, 'post_content' => $content ) );
	}

	private function planned(): \PurgeKit\Core\PurgePlan {
		$s = Plugin::services();
		return ( new \PurgeKit\Core\Planner( $s->expander, home_url(), array(), array( $s->expander, 'tags' ) ) )->plan( $s->queue->drain( false ) );
	}

	public function test_edit_preserves_old_and_new_author_date_slug_and_categories(): void {
		$old_author = self::factory()->user->create();
		$new_author = self::factory()->user->create();
		$a = self::factory()->category->create();
		$b = self::factory()->category->create();
		$id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $old_author, 'post_date' => '2020-02-03 10:00:00', 'post_category' => array( $a ) ) );
		$old = get_permalink( $id );
		Plugin::services()->queue->drain();
		wp_update_post( array( 'ID' => $id, 'post_author' => $new_author, 'post_name' => 'new-address', 'post_date' => '2021-03-04 10:00:00', 'post_category' => array( $b ) ) );
		$p = $this->planned();
		foreach ( array( $old, get_permalink( $id ), get_author_posts_url( $old_author ), get_author_posts_url( $new_author ), get_category_link( $a ), get_category_link( $b ), get_day_link( 2020, 2, 3 ), get_day_link( 2021, 3, 4 ) ) as $url ) {
			$this->assertContains( $url, $p->urls, $url );
		}
		$this->assertContains( 't-' . $a, $p->tags );
		$this->assertContains( 't-' . $b, $p->tags );
	}

	public function test_direct_taxonomy_removal_keeps_the_old_archive(): void {
		$a = self::factory()->category->create();
		$id = $this->page( '', 'post' );
		wp_set_object_terms( $id, array( $a ), 'category' );
		Plugin::services()->queue->drain();
		wp_remove_object_terms( $id, $a, 'category' );
		$this->assertContains( get_category_link( $a ), $this->planned()->urls );
	}

	public function test_permanent_deletion_preserves_the_deleted_post_url_and_tags(): void {
		$id = $this->page( '', 'post' );
		$url = get_permalink( $id );
		Plugin::services()->queue->drain();
		wp_delete_post( $id, true );
		$p = $this->planned();
		$this->assertContains( $url, $p->urls );
		$this->assertContains( 'p-' . $id, $p->tags );
	}

	public function test_deleted_term_retains_its_old_archive(): void {
		$id = self::factory()->category->create();
		$url = get_category_link( $id );
		Plugin::services()->queue->drain();
		wp_delete_term( $id, 'category' );
		$this->assertContains( $url, $this->planned()->urls );
	}

	public function test_nested_shared_blocks_and_cycles_terminate_and_find_consumers(): void {
		$a = $this->page( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->', 'wp_block' );
		$b = $this->page( '<!-- wp:block {"ref":' . $a . '} /-->', 'wp_block' );
		$page = $this->page( '<!-- wp:block {"ref":' . $b . '} /-->' );
		wp_update_post( array( 'ID' => $a, 'post_content' => '<!-- wp:block {"ref":' . $b . '} /-->' ) );
		$d = ( new Dependencies() )->affected( get_post( $a ) );
		$this->assertFalse( $d['all'] );
		$this->assertContains( get_permalink( $page ), $d['urls'] );
	}

	public function test_query_loop_in_a_shared_block_reaches_its_page(): void {
		$post = $this->page( '', 'post' );
		$block = $this->page( '<!-- wp:query {"query":{"postType":"post"}} --><!-- wp:post-template /--><!-- /wp:query -->', 'wp_block' );
		$page = $this->page( '<!-- wp:block {"ref":' . $block . '} /-->' );
		$d = ( new Dependencies() )->affected( get_post( $post ) );
		$this->assertContains( get_permalink( $page ), $d['urls'] );
	}

	public function test_loop_of_another_post_type_is_not_selected(): void {
		$post = $this->page( '', 'post' );
		$page = $this->page( '<!-- wp:query {"query":{"postType":"page"}} /-->' );
		$this->assertNotContains( get_permalink( $page ), ( new Dependencies() )->affected( get_post( $post ) )['urls'] );
	}

	public function test_elementor_metadata_and_shortcode_and_beaver_references(): void {
		register_post_type( 'elementor_library', array( 'public' => false ) );
		$id = $this->page( '', 'elementor_library' );
		$a = $this->page( '' );
		update_post_meta( $a, '_elementor_data', wp_slash( wp_json_encode( array( array( 'settings' => array( 'template_id' => (string) $id ) ) ) ) ) );
		$b = $this->page( '[elementor-template id="' . $id . '"]' );
		$c = $this->page( '' );
		update_post_meta( $c, '_fl_builder_data', array( 'node' => (object) array( 'settings' => (object) array( 'node_template_id' => $id ) ) ) );
		$d = ( new Dependencies() )->affected( get_post( $id ) );
		foreach ( array( $a, $b, $c ) as $page ) {
			$this->assertContains( get_permalink( $page ), $d['urls'] );
		}
		update_post_meta( $id, '_elementor_conditions', array( 'include/general' ) );
		$this->assertTrue( ( new Dependencies() )->affected( get_post( $id ) )['all'] );
		unregister_post_type( 'elementor_library' );
	}

	public function test_global_template_uses_full_purge_and_shared_block_deletion_queues_consumers(): void {
		$template = $this->page( '', 'wp_template_part' );
		$this->assertTrue( ( new Dependencies() )->affected( get_post( $template ) )['all'] );
		$block = $this->page( '', 'wp_block' );
		$page = $this->page( '<!-- wp:block {"ref":' . $block . '} /-->' );
		Plugin::services()->queue->drain();
		wp_delete_post( $block, true );
		$this->assertContains( get_permalink( $page ), $this->planned()->urls );
	}

	public function test_snapshot_expansion_is_not_recomputed_from_the_changed_post(): void {
		$c = Change::forUrls( 'post', array( home_url( '/old/' ) ), array( 'post_id' => 999999, 'snapshot' => true, 'tags' => array( 't-12' ) ) );
		$s = Plugin::services();
		$this->assertSame( array(), $s->expander->expand( $c ) );
		$this->assertSame( array( 't-12' ), $s->expander->tags( $c ) );
	}
	public function test_new_taxonomy_assignment_and_metadata_only_builder_edits_are_queued(): void {
		$id = $this->page( '', 'post' );
		$category = self::factory()->category->create();
		Plugin::services()->queue->drain();
		wp_set_object_terms( $id, array( $category ), 'category', true );
		$this->assertContains( get_category_link( $category ), $this->planned()->urls );
		update_post_meta( $id, '_elementor_data', '[]' );
		$this->assertContains( get_permalink( $id ), $this->planned()->urls );
	}

	public function test_query_loop_in_a_global_template_requires_a_full_purge(): void {
		$id = $this->page( '', 'post' );
		$this->page( '<!-- wp:query {"query":{"postType":"post"}} /-->', 'wp_template' );
		$this->assertTrue( ( new Dependencies() )->affected( get_post( $id ) )['all'] );
	}

	public function test_dependency_scan_overflow_falls_back_to_a_full_purge(): void {
		global $wpdb;
		$id = $this->page( '', 'post' );
		for ( $i = 0; $i < 501; ++$i ) {
			$wpdb->insert( $wpdb->posts, array( 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<!-- wp:query /-->' ) );
		}
		$this->assertTrue( ( new Dependencies() )->affected( get_post( $id ) )['all'] );
	}

	public function test_large_sites_without_relevant_blocks_do_not_escalate_to_a_full_purge(): void {
		global $wpdb;
		$id = $this->page( '', 'post' );
		for ( $i = 0; $i < 501; ++$i ) {
			$wpdb->insert( $wpdb->posts, array( 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<!-- wp:paragraph /-->' ) );
		}
		$this->assertFalse( ( new Dependencies() )->affected( get_post( $id ) )['all'] );
	}

	public function test_removing_global_builder_conditions_preserves_the_previous_full_purge_requirement(): void {
		register_post_type( 'elementor_library', array( 'public' => false ) );
		$id = $this->page( '', 'elementor_library' );
		update_post_meta( $id, '_elementor_conditions', array( 'include/general' ) );
		Plugin::services()->queue->drain();
		delete_post_meta( $id, '_elementor_conditions' );
		$this->assertTrue( $this->planned()->all );
		unregister_post_type( 'elementor_library' );
	}

}
