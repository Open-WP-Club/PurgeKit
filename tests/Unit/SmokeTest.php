<?php
namespace PurgeKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
	public function test_autoloader_loads_core_namespace(): void {
		$this->assertTrue( class_exists( \PurgeKit\Core\Change::class ) );
	}
}
