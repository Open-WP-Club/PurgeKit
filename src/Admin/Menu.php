<?php
namespace PurgeKit\Admin;

final class Menu {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PURGEKIT_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
	}

	/** A "Settings" link next to Deactivate on the Plugins screen. */
	public function action_links( array $links ): array {
		$url = esc_url( self::url() );
		return array_merge( array( '<a href="' . $url . '">' . esc_html__( 'Settings', 'purgekit' ) . '</a>' ), $links );
	}

	public static function url(): string {
		return admin_url( 'options-general.php?page=purgekit' );
	}

	/** Takes the person who just activated the plugin straight to its screen (not for bulk or network activation). */
	public function activation_redirect(): void {
		if ( ! get_transient( 'purgekit_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'purgekit_activation_redirect' );
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( apply_filters( 'purgekit_capability', 'manage_options' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
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
		$dir      = plugin_dir_path( PURGEKIT_FILE ) . 'build/';
		$asset    = file_exists( $dir . 'index.asset.php' ) ? require $dir . 'index.asset.php' : array(
			'dependencies' => array(),
			'version'      => PURGEKIT_VERSION,
		);
		$csv_base = HistoryExport::url();
		wp_enqueue_script( 'purgekit-admin', plugins_url( 'build/index.js', PURGEKIT_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script(
			'purgekit-admin',
			'window.purgekitData = ' . wp_json_encode(
				array(
					'csvUrl'      => $csv_base,
					'statsCsvUrl' => \PurgeKit\Admin\StatsExport::url(),
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( 'purgekit-admin', 'purgekit' );
		wp_enqueue_style( 'wp-components' );
		if ( file_exists( $dir . 'style-index.css' ) ) {
			wp_enqueue_style( 'purgekit-admin', plugins_url( 'build/style-index.css', PURGEKIT_FILE ), array( 'wp-components' ), $asset['version'] );
			wp_style_add_data( 'purgekit-admin', 'rtl', 'replace' );
		}
	}
}
