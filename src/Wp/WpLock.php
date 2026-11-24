<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Lock;

/** Atomic via add_option (fails if the row exists); stale locks expire after $ttl seconds. */
final class WpLock implements Lock {
	public function acquire( string $name, int $ttl ): bool {
		$key = WpStore::PREFIX . 'lock_' . $name;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		$taken = (int) get_option( $key, 0 );
		if ( time() - $taken > $ttl ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}
		return false;
	}

	public function release( string $name ): void {
		delete_option( WpStore::PREFIX . 'lock_' . $name );
	}
}
