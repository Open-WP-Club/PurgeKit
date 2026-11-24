<?php
namespace PurgeKit\Admin;

use PurgeKit\Network\NetworkDefaults;
use PurgeKit\Network\SiteRunner;
use PurgeKit\Services;
use PurgeKit\Settings\Settings;
use PurgeKit\Wp\NetworkStore;

/** Network admin: default settings for all sites, and "purge every site". Plain forms, no React. */
final class NetworkPage {
	private const SLUG = 'purgekit-network';

	public function register(): void {
		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		add_action( 'network_admin_edit_purgekit_network_save', array( $this, 'handle_save' ) );
		add_action( 'network_admin_edit_purgekit_network_flush', array( $this, 'handle_flush' ) );
	}

	public function add_page(): void {
		add_submenu_page( 'settings.php', 'PurgeKit', 'PurgeKit', 'manage_network_options', self::SLUG, array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$defaults = ( new NetworkDefaults( new NetworkStore() ) )->all();
		$mode     = (string) ( $defaults['mode'] ?? '' );
		$schedule = (string) ( $defaults['schedule'] ?? '' );
		$results  = get_site_transient( $this->result_key() );
		delete_site_transient( $this->result_key() );
		$saved = isset( $_GET['purgekit'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['purgekit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		?>
		<div class="wrap">
			<h1>PurgeKit</h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Defaults saved.', 'purgekit' ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $results ) && ! $results ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'No site has PurgeKit active, so nothing was purged.', 'purgekit' ); ?></p></div>
			<?php elseif ( is_array( $results ) ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Purge finished:', 'purgekit' ); ?></p><ul>
				<?php foreach ( $results as $row ) : ?>
					<li><?php echo esc_html( $row['url'] . ': ' . $row['status'] ); ?></li>
				<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Default settings for all sites', 'purgekit' ); ?></h2>
			<p><?php esc_html_e( 'A site uses these until someone saves its own value in Settings → PurgeKit.', 'purgekit' ); ?></p>
			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=purgekit_network_save' ) ); ?>">
				<?php wp_nonce_field( 'purgekit_network_save' ); ?>
				<table class="form-table"><tbody>
					<tr><th><label for="pk-mode"><?php esc_html_e( 'When something changes', 'purgekit' ); ?></label></th><td>
						<select id="pk-mode" name="mode"><option value=""><?php esc_html_e( '(not set)', 'purgekit' ); ?></option>
						<?php foreach ( Settings::MODES as $m ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $mode, $m ); ?>><?php echo esc_html( $m ); ?></option>
						<?php endforeach; ?></select></td></tr>
					<tr><th><label for="pk-schedule"><?php esc_html_e( 'Schedule', 'purgekit' ); ?></label></th><td>
						<select id="pk-schedule" name="schedule"><option value=""><?php esc_html_e( '(not set)', 'purgekit' ); ?></option>
						<?php foreach ( Settings::SCHEDULES as $sch ) : ?>
							<option value="<?php echo esc_attr( $sch ); ?>" <?php selected( $schedule, $sch ); ?>><?php echo esc_html( $sch ); ?></option>
						<?php endforeach; ?></select></td></tr>
					<?php
					foreach ( array(
						'skip_if_unchanged' => 'Skip if nothing changed',
						'warm_enabled'      => 'Warm the cache after a purge',
					) as $key => $label ) :
						?>
						<tr><th><label for="pk-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td>
							<select id="pk-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
								<option value=""><?php esc_html_e( '(not set)', 'purgekit' ); ?></option>
								<option value="1" <?php selected( isset( $defaults[ $key ] ) && $defaults[ $key ] ); ?>><?php esc_html_e( 'Yes', 'purgekit' ); ?></option>
								<option value="0" <?php selected( isset( $defaults[ $key ] ) && ! $defaults[ $key ] ); ?>><?php esc_html_e( 'No', 'purgekit' ); ?></option>
							</select></td></tr>
					<?php endforeach; ?>
				</tbody></table>
				<?php submit_button( __( 'Save defaults', 'purgekit' ) ); ?>
			</form>
			<h2><?php esc_html_e( 'Purge every site', 'purgekit' ); ?></h2>
			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=purgekit_network_flush' ) ); ?>">
				<?php wp_nonce_field( 'purgekit_network_flush' ); ?>
				<?php submit_button( __( 'Purge all sites now', 'purgekit' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		check_admin_referer( 'purgekit_network_save' );
		$this->guard();
		$defaults = new NetworkDefaults( new NetworkStore() );
		$patch    = array();
		$clear    = array();
		foreach ( NetworkDefaults::KEYS as $key ) {
			$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
			if ( '' === $raw ) {
				$clear[] = $key;
				continue;
			}
			$patch[ $key ] = in_array( $key, NetworkDefaults::FLAGS, true ) ? '1' === $raw : $raw;
		}
		$defaults->update( $patch );
		$defaults->clear( $clear );
		// Sites that now inherit a different mode need their scheduled events adjusted.
		( new SiteRunner() )->eachSite( static fn( int $blog_id, Services $s ) => $s->cron->sync() );
		$this->back( 'saved' );
	}

	public function handle_flush(): void {
		check_admin_referer( 'purgekit_network_flush' );
		$this->guard();
		set_site_transient( $this->result_key(), ( new SiteRunner() )->flushAll( 'network' ), 5 * MINUTE_IN_SECONDS );
		$this->back( 'flushed' );
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'purgekit' ), 403 );
		}
	}

	private function back( string $what ): void {
		wp_safe_redirect( add_query_arg( array( 'purgekit' => $what ), network_admin_url( 'settings.php?page=' . self::SLUG ) ) );
		exit;
	}

	private function result_key(): string {
		return 'purgekit_net_result_' . get_current_user_id();
	}
}
