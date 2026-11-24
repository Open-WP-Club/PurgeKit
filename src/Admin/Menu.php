<?php
namespace PurgeKit\Admin;

final class Menu {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_page(): void {
		add_options_page( 'PurgeKit', 'PurgeKit', apply_filters( 'purgekit_capability', 'manage_options' ), 'purgekit', array( $this, 'render' ) );
	}

	public function render(): void {
		echo '<div id="purgekit-root"></div>';
	}

	public function enqueue( string $hook ): void {
		if ( 'settings_page_purgekit' !== $hook ) {
			return;
		}
		$dir   = plugin_dir_path( PURGEKIT_FILE ) . 'build/';
		$asset = file_exists( $dir . 'index.asset.php' ) ? require $dir . 'index.asset.php' : array(
			'dependencies' => array(),
			'version'      => PURGEKIT_VERSION,
		);
		wp_enqueue_script( 'purgekit-admin', plugins_url( 'build/index.js', PURGEKIT_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'purgekit-admin', 'purgekit' );
		wp_enqueue_style( 'wp-components' );
	}
}
