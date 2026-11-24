<?php
namespace PurgeKit\Tests\Fakes;

use PurgeKit\Core\Store;

final class FakeStore implements Store {
	public array $data = array();
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? $fallback;
	}
	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}
	public function delete( string $key ): void {
		unset( $this->data[ $key ] );
	}
}
