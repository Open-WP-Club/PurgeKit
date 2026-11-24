<?php
namespace PurgeKit\Core;

interface Lock {
	public function acquire( string $name, int $ttl ): bool;
	public function release( string $name ): void;
}
