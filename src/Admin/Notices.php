<?php
namespace PurgeKit\Admin;

use PurgeKit\Services;

/** Notify mode: admin notice and admin-bar dot while changes are pending. */
final class Notices {
	public function __construct( private Services $services ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_bar_menu', array( $this, 'bar' ), 90 );
		add_action( 'admin_post_purgekit_flush', array( $this, 'handle_flush' ) );
	}

	private function allowed(): bool {
		return current_user_can( apply_filters( 'purgekit_capability', 'manage_options' ) );
	}

	public function notice(): void {
		$pending = $this->services->queue->count();
		if ( ! $this->allowed() || 'notify' !== $this->services->settings->mode() || 0 === $pending ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s" class="button button-primary">%s</a></p></div>',
			esc_html__( 'Your site has changed. Caches may be showing the old version.', 'purgekit' ),
			esc_url( $this->flush_url() ),
			esc_html__( 'Flush now', 'purgekit' )
		);
	}

	public function bar( \WP_Admin_Bar $bar ): void {
		if ( ! $this->allowed() ) {
			return;
		}
		$pending = $this->services->queue->count();
		$dot     = $pending > 0 ? '<span style="color:#dba617">&#9679;</span> ' : '';
		$bar->add_node(
			array(
				'id'    => 'purgekit',
				'title' => $dot . esc_html__( 'PurgeKit', 'purgekit' ),
				'href'  => $pending > 0 ? $this->flush_url() : admin_url( 'options-general.php?page=purgekit' ),
			)
		);
	}

	public function handle_flush(): void {
		check_admin_referer( 'purgekit_flush' );
		if ( ! $this->allowed() ) {
			wp_die( esc_html__( 'Not allowed.', 'purgekit' ), 403 );
		}
		$this->services->flusher->flushAll( 'manual' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	private function flush_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=purgekit_flush' ), 'purgekit_flush' );
	}
}
