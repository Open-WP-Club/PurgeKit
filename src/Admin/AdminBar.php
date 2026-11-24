<?php
namespace PurgeKit\Admin;

use PurgeKit\Services;

/** "Purge this page" in the admin bar and in post list rows. */
final class AdminBar {
	public function __construct( private Services $services ) {}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_purgekit_flush_url', array( $this, 'handle_flush_url' ) );
	}

	private function allowed(): bool {
		return current_user_can( apply_filters( 'purgekit_capability', 'manage_options' ) );
	}

	private function url_for( string $url ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'purgekit_flush_url',
					'url'    => rawurlencode( $url ),
				),
				admin_url( 'admin-post.php' )
			),
			'purgekit_flush_url'
		);
	}

	public function add_node( \WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! $this->allowed() ) {
			return;
		}
		$current = home_url( add_query_arg( array() ) );
		$bar->add_node(
			array(
				'id'     => 'purgekit-page',
				'parent' => 'purgekit',
				'title'  => esc_html__( 'Purge this page', 'purgekit' ),
				'href'   => $this->url_for( $current ),
			)
		);
	}

	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( 'publish' === $post->post_status && $this->allowed() ) {
			$actions['purgekit'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->url_for( (string) get_permalink( $post ) ) ), esc_html__( 'Purge cache', 'purgekit' ) );
		}
		return $actions;
	}

	public function handle_flush_url(): void {
		check_admin_referer( 'purgekit_flush_url' );
		if ( ! $this->allowed() ) {
			wp_die( esc_html__( 'Not allowed.', 'purgekit' ), 403 );
		}
		$url = isset( $_REQUEST['url'] ) ? esc_url_raw( rawurldecode( sanitize_text_field( wp_unslash( (string) $_REQUEST['url'] ) ) ) ) : '';
		if ( '' !== $url ) {
			$this->services->flusher->flushUrls( 'admin', array( $url ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
