<?php
namespace PurgeKit\Tests\Fakes;

use PurgeKit\Core\Lock;

final class FakeLock implements Lock {
	public bool $busy = false;
	public int $released = 0;
	public function acquire( string $name, int $ttl ): bool {
		return ! $this->busy;
	}
	public function release( string $name ): void {
		++$this->released;
	}
}
