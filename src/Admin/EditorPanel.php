<?php
namespace PurgeKit\Admin;

/** The "PurgeKit cache" panel in the block editor sidebar. */
final class EditorPanel {
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		// The panel needs the post editor's own scripts, which the site editor does not load.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! current_user_can( apply_filters( 'purgekit_capability', 'manage_options' ) ) ) {
			return;
		}
		$dir = plugin_dir_path( PURGEKIT_FILE ) . 'build/';
		if ( ! file_exists( $dir . 'editor.js' ) ) {
			return;
		}
		$asset = file_exists( $dir . 'editor.asset.php' ) ? require $dir . 'editor.asset.php' : array(
			'dependencies' => array(),
			'version'      => PURGEKIT_VERSION,
		);
		wp_enqueue_script( 'purgekit-editor', plugins_url( 'build/editor.js', PURGEKIT_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'purgekit-editor', 'purgekit' );
	}
}
