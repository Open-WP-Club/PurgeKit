<?php
namespace PurgeKit\Core;

interface Store {
	public function get( string $key, mixed $fallback = null ): mixed;
	public function set( string $key, mixed $value ): void;
	public function delete( string $key ): void;
}
