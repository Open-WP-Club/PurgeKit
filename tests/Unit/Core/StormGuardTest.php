<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Core\StormGuard;
use PurgeKit\Tests\Fakes\FakeStore;

final class StormGuardTest extends TestCase {
	private function full(): Run {
		return new Run( Run::OK, array(), PurgePlan::everything() );
	}

	public function test_trips_after_the_limit_inside_the_window(): void {
		$g = new StormGuard( new FakeStore(), 3, 600 );
		$this->assertFalse( $g->record( 'auto', $this->full(), 1000 ) );
		$this->assertFalse( $g->record( 'auto', $this->full(), 1100 ) );
		$this->assertTrue( $g->record( 'auto', $this->full(), 1200 ) );
		$this->assertSame( 3, $g->tripped( 1201 )['count'] );
		$this->assertFalse( $g->record( 'auto', $this->full(), 1300 ), 'Only the run that trips it reports it.' );
	}

	public function test_old_flushes_fall_out_of_the_window(): void {
		$g = new StormGuard( new FakeStore(), 3, 600 );
		$g->record( 'auto', $this->full(), 1000 );
		$g->record( 'auto', $this->full(), 1100 );
		$this->assertFalse( $g->record( 'auto', $this->full(), 2000 ) );
		$this->assertNull( $g->tripped( 2001 ) );
	}

	public function test_people_and_single_pages_do_not_count(): void {
		$g = new StormGuard( new FakeStore(), 2, 600 );
		$this->assertFalse( $g->record( 'manual', $this->full(), 1 ) );
		$this->assertFalse( $g->record( 'manual', $this->full(), 2 ) );
		$this->assertFalse( $g->record( 'auto', new Run( Run::OK, array(), PurgePlan::forUrls( array( 'https://a.test/' ) ) ), 3 ) );
		$this->assertFalse( $g->record( 'auto', new Run( Run::SKIPPED, array(), PurgePlan::everything() ), 4 ) );
		$this->assertNull( $g->tripped( 5 ) );
	}

	public function test_it_expires_and_can_be_reset(): void {
		$g = new StormGuard( new FakeStore(), 2, 600, 100 );
		$g->record( 'auto', $this->full(), 1000 );
		$g->record( 'auto', $this->full(), 1001 );
		$this->assertNotNull( $g->tripped( 1050 ) );
		$this->assertNull( $g->tripped( 1200 ), 'It clears itself after the ttl.' );
		$g->record( 'auto', $this->full(), 2000 );
		$g->record( 'auto', $this->full(), 2001 );
		$g->reset();
		$this->assertNull( $g->tripped( 2002 ) );
	}
}
