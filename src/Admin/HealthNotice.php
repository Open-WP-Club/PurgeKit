<?php
namespace PurgeKit\Admin;

use PurgeKit\Core\Health;
use PurgeKit\Services;

/** Site Health test plus an admin notice, so a failing purge is not only visible inside PurgeKit. */
final class HealthNotice {
	public function __construct( private Services $services ) {}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/** @return array<int,array{id:string,status:string,label:string,description:string}> */
	public function checks(): array {
		$last = $this->services->store->get( 'last_run' );
		return Health::checks( is_array( $last ) ? $last : null, $this->services->guard->reason(), count( $this->services->registry->active() ) );
	}

	public function tests( array $tests ): array {
		$tests['direct']['purgekit'] = array(
			'label' => __( 'PurgeKit cache purging', 'purgekit' ),
			'test'  => array( $this, 'site_health' ),
		);
		return $tests;
	}

	public function site_health(): array {
		$checks      = $this->checks();
		$worst       = Health::worst( $checks );
		$description = '';
		foreach ( $checks as $c ) {
			$description .= '<p><strong>' . esc_html( $c['label'] ) . '.</strong> ' . esc_html( $c['description'] ) . '</p>';
		}
		return array(
			'label'       => esc_html( 'good' === $worst ? __( 'Cache purging is working', 'purgekit' ) : $checks[0]['label'] ),
			'status'      => $worst,
			'badge'       => array(
				'label' => __( 'Performance', 'purgekit' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'options-general.php?page=purgekit' ) ), esc_html__( 'Open PurgeKit', 'purgekit' ) ),
			'test'        => 'purgekit',
		);
	}

	/** A failed purge is shown on every admin screen; softer problems only on the PurgeKit screen. */
	public function notice(): void {
		if ( ! current_user_can( apply_filters( 'purgekit_capability', 'manage_options' ) ) ) {
			return;
		}
		$screen  = get_current_screen();
		$on_page = $screen && 'settings_page_purgekit' === $screen->id;
		foreach ( $this->checks() as $c ) {
			if ( Health::GOOD === $c['status'] || 'layers' === $c['id'] || ( Health::CRITICAL !== $c['status'] && ! $on_page ) ) {
				continue;
			}
			printf(
				'<div class="notice notice-%s"><p><strong>PurgeKit:</strong> %s. %s <a href="%s">%s</a></p></div>',
				Health::CRITICAL === $c['status'] ? 'error' : 'warning',
				esc_html( $c['label'] ),
				esc_html( $c['description'] ),
				esc_url( admin_url( 'options-general.php?page=purgekit' ) ),
				esc_html__( 'Open PurgeKit', 'purgekit' )
			);
		}
	}
}
