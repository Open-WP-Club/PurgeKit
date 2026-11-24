<?php
namespace PurgeKit\Settings;

use PurgeKit\Core\Store;

/** Typed, validated accessors over the Store. Never holds secrets. */
final class Settings {
	public const MODES     = array( 'notify', 'auto', 'scheduled' );
	public const SCHEDULES = array( 'hourly', '3h', '6h', '12h', 'daily', 'weekly' );

	public function __construct( private Store $store ) {}

	public function mode(): string {
		$v = $this->store->get( 'mode', 'notify' );
		return in_array( $v, self::MODES, true ) ? $v : 'notify';
	}

	public function schedule(): string {
		$v = $this->store->get( 'schedule', 'daily' );
		return in_array( $v, self::SCHEDULES, true ) ? $v : 'daily';
	}

	public function skipIfUnchanged(): bool {
		return (bool) $this->store->get( 'skip_if_unchanged', true );
	}

	public function warmEnabled(): bool {
		return (bool) $this->store->get( 'warm_enabled', true );
	}

	public function all(): array {
		return array(
			'mode'              => $this->mode(),
			'schedule'          => $this->schedule(),
			'skip_if_unchanged' => $this->skipIfUnchanged(),
			'warm_enabled'      => $this->warmEnabled(),
			'cloudflare_zone'   => (string) $this->store->get( 'cloudflare_zone', '' ),
			'varnish_enabled'   => (bool) $this->store->get( 'varnish_enabled', false ),
		);
	}

	/** Unknown keys and invalid values are ignored. */
	public function update( array $patch ): void {
		if ( isset( $patch['mode'] ) && in_array( $patch['mode'], self::MODES, true ) ) {
			$this->store->set( 'mode', $patch['mode'] );
		}
		if ( isset( $patch['schedule'] ) && in_array( $patch['schedule'], self::SCHEDULES, true ) ) {
			$this->store->set( 'schedule', $patch['schedule'] );
		}
		foreach ( array( 'skip_if_unchanged', 'warm_enabled', 'varnish_enabled' ) as $flag ) {
			if ( isset( $patch[ $flag ] ) ) {
				$this->store->set( $flag, (bool) $patch[ $flag ] );
			}
		}
		if ( isset( $patch['cloudflare_zone'] ) && is_string( $patch['cloudflare_zone'] ) ) {
			$this->store->set( 'cloudflare_zone', trim( $patch['cloudflare_zone'] ) );
		}
	}
}
