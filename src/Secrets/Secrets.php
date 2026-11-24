<?php
namespace PurgeKit\Secrets;

use PurgeKit\Core\Store;

final class Secrets {
	public function __construct( private Store $store, private string $key_material ) {}

	public function get( string $name ): ?string {
		$constant = 'PURGEKIT_' . $name;
		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return constant( $constant );
		}
		$stored = $this->store->get( 'secret_' . $name );
		return is_string( $stored ) ? $this->decrypt( $stored ) : null;
	}

	public function has( string $name ): bool {
		return null !== $this->get( $name );
	}

	public function set( string $name, string $plain ): void {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->key() );
		$this->store->set( 'secret_' . $name, base64_encode( $nonce . $cipher ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function decrypt( string $encoded ): ?string {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->key() );
		return false === $plain ? null : $plain;
	}

	private function key(): string {
		return sodium_crypto_generichash( $this->key_material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
