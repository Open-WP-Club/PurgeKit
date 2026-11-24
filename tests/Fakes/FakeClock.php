<?php
namespace PurgeKit\Tests\Fakes;

use PurgeKit\Core\Clock;

final class FakeClock implements Clock {
	public int $t = 0;
	public function now(): int {
		return $this->t += 5;
	}
}
