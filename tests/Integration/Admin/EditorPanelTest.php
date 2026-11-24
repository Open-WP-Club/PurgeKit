<?php
namespace PurgeKit\Tests\Integration\Admin;

use PurgeKit\Admin\EditorPanel;

final class EditorPanelTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_dequeue_script( 'purgekit-editor' );
		wp_deregister_script( 'purgekit-editor' );
	}

	public function tear_down(): void {
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	private function enqueue_as( string $role, string $screen_id ): bool {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		set_current_screen( $screen_id );
		( new EditorPanel() )->enqueue();
		return wp_script_is( 'purgekit-editor', 'enqueued' );
	}

	public function test_the_panel_loads_in_the_post_editor_for_an_administrator(): void {
		$this->assertFileExists( plugin_dir_path( PURGEKIT_FILE ) . 'build/editor.js', 'Run pnpm run build first.' );
		$this->assertTrue( $this->enqueue_as( 'administrator', 'post' ) );
		$this->assertContains( 'wp-edit-post', wp_scripts()->registered['purgekit-editor']->deps );
	}

	public function test_it_does_not_load_for_people_who_cannot_purge_or_outside_the_post_editor(): void {
		$this->assertFalse( $this->enqueue_as( 'editor', 'post' ), 'An editor cannot purge, so there is no panel.' );
		$this->assertFalse( $this->enqueue_as( 'administrator', 'dashboard' ) );
		$this->assertFalse( $this->enqueue_as( 'administrator', 'site-editor' ), 'The site editor has no post panels.' );
	}
}
