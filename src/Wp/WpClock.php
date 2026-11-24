<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Clock;

final class WpClock implements Clock {
	public function now(): int {
		return (int) round( microtime( true ) * 1000 );
	}
}
