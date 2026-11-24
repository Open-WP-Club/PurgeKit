<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\ScheduledPurges;
use PurgeKit\Tests\Fakes\FakeStore;

final class ScheduledPurgesTest extends TestCase {
	private const NOW = 1000000;

	private function purges(): ScheduledPurges {
		return new ScheduledPurges( new FakeStore(), 'https://site.test' );
	}

	public function test_adds_lists_soonest_first_and_takes(): void {
		$p = $this->purges();
		$b = $p->add( self::NOW + 200, array(), 'later', self::NOW );
		$a = $p->add( self::NOW + 100, array( 'https://site.test/sale/' ), 'sale', self::NOW );
		$this->assertSame( array( $a['id'], $b['id'] ), array_column( $p->all(), 'id' ) );
		$this->assertSame( $a, $p->find( $a['id'] ) );
		$this->assertSame( $a, $p->take( $a['id'] ) );
		$this->assertNull( $p->take( $a['id'] ), 'Taking twice finds nothing.' );
		$this->assertCount( 1, $p->all() );
	}

	public function test_rejects_the_past_far_future_and_too_many(): void {
		$p = $this->purges();
		$this->assertNull( $p->add( self::NOW, array(), '', self::NOW ) );
		$this->assertNull( $p->add( self::NOW - 5, array(), '', self::NOW ) );
		$this->assertNull( $p->add( self::NOW + ScheduledPurges::MAX_AHEAD + 1, array(), '', self::NOW ) );
		for ( $i = 1; $i <= ScheduledPurges::MAX; $i++ ) {
			$this->assertNotNull( $p->add( self::NOW + $i, array(), '', self::NOW ) );
		}
		$this->assertNull( $p->add( self::NOW + 99, array(), '', self::NOW ) );
	}

	public function test_only_own_host_urls_are_kept_and_unusable_urls_are_refused(): void {
		$p    = $this->purges();
		$item = $p->add( self::NOW + 10, array( 'https://site.test/a', 'https://evil.test/b', 'file:///etc/passwd', 'https://u:p@site.test/c', 5 ), '', self::NOW );
		$this->assertSame( array( 'https://site.test/a' ), $item['urls'] );
		$this->assertNull( $p->add( self::NOW + 10, array( 'https://evil.test/b' ), '', self::NOW ), 'URLs given but none usable must not become "purge everything".' );
	}

	public function test_note_is_trimmed_and_capped(): void {
		$item = $this->purges()->add( self::NOW + 10, array(), '  ' . str_repeat( 'n', 300 ), self::NOW );
		$this->assertSame( 100, strlen( $item['note'] ) );
	}
}
