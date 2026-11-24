<?php
namespace PurgeKit\Tests\Integration\Admin;

use PurgeKit\Admin\AdminBar;
use PurgeKit\Admin\Menu;
use PurgeKit\Plugin;

final class AdminTest extends \WP_UnitTestCase {
	public function test_menu_page_registered_for_admin_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new Menu() )->add_page();
		$this->assertNotEmpty( menu_page_url( 'purgekit', false ) );

		global $submenu;
		$submenu = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		( new Menu() )->add_page();
		$this->assertArrayNotHasKey( 'options-general.php', $submenu );
	}

	public function test_purge_this_page_node_only_on_frontend_for_admins(): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$bar = new \WP_Admin_Bar();
		( new AdminBar( Plugin::services() ) )->add_node( $bar );
		$node = $bar->get_node( 'purgekit-page' );
		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'purgekit_flush_url', $node->href );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$bar = new \WP_Admin_Bar();
		( new AdminBar( Plugin::services() ) )->add_node( $bar );
		$this->assertNull( $bar->get_node( 'purgekit-page' ) );
	}

	public function test_row_action_added_for_published_post_with_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post    = get_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
		$actions = ( new AdminBar( Plugin::services() ) )->row_actions( array(), $post );
		$this->assertArrayHasKey( 'purgekit', $actions );
		$this->assertStringContainsString( rawurlencode( get_permalink( $post ) ), $actions['purgekit'] );

		$draft = get_post( self::factory()->post->create( array( 'post_status' => 'draft' ) ) );
		$this->assertArrayNotHasKey( 'purgekit', ( new AdminBar( Plugin::services() ) )->row_actions( array(), $draft ) );
	}

	public function test_flush_url_handler_requires_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['url'] = home_url( '/x/' );
		$this->expectException( \WPDieException::class );
		( new AdminBar( Plugin::services() ) )->handle_flush_url();
	}
}
