<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeLayer;

final class RegistryTest extends TestCase {
	private function ids( array $layers ): array {
		return array_map( static fn( $l ) => $l->id(), $layers );
	}

	public function test_active_sorted_by_tier_ascending(): void {
		$r = new Registry( array( new FakeLayer( 'cdn', 50 ), new FakeLayer( 'obj', 10 ), new FakeLayer( 'host', 30 ) ) );
		$this->assertSame( array( 'obj', 'host', 'cdn' ), $this->ids( $r->active() ) );
	}

	public function test_active_excludes_undetected(): void {
		$r = new Registry( array( new FakeLayer( 'a', 10 ), new FakeLayer( 'b', 20, false ) ) );
		$this->assertSame( array( 'a' ), $this->ids( $r->active() ) );
		$this->assertCount( 2, $r->all() );
	}

	public function test_equal_tiers_keep_registration_order(): void {
		$r = new Registry( array( new FakeLayer( 'z', 20 ), new FakeLayer( 'a', 20 ), new FakeLayer( 'm', 20 ) ) );
		$this->assertSame( array( 'z', 'a', 'm' ), $this->ids( $r->all() ) );
	}

	public function test_find_returns_layer_or_null(): void {
		$r = new Registry( array( new FakeLayer( 'a', 10 ) ) );
		$this->assertSame( 'a', $r->find( 'a' )->id() );
		$this->assertNull( $r->find( 'nope' ) );
	}
}
