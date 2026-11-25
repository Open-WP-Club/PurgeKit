<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Impact;

final class ImpactTest extends TestCase {
	private const NOW = 1764072000; // 2025-11-25 12:00 UTC.

	private function row( string $day, bool $all, string $status = 'ok', int $urls = 0, array $extra = array() ): array {
		return $extra + array(
			'created_at' => "$day 10:00:00",
			'source'     => 'auto',
			'status'     => $status,
			'all'        => $all,
			'urls_total' => $urls,
			'duration_ms' => 100,
			'results'    => array( array( 'layer' => 'a', 'status' => 'ok' ), array( 'layer' => 'b', 'status' => 'failed' ) ),
			'trigger'    => array( 'changes' => array( 'post' ) ),
		);
	}

	public function test_counts_targeted_and_full_flushes_and_ignores_skipped_ones(): void {
		$rows = array(
			$this->row( '2025-11-25', false, 'ok', 6 ),
			$this->row( '2025-11-25', false, 'partial', 4 ),
			$this->row( '2025-11-24', true ),
			$this->row( '2025-11-24', true, 'skipped' ),
		);
		$s = Impact::summarise( $rows, self::NOW, 7 );
		$this->assertSame( 3, $s['flushes'] );
		$this->assertSame( 2, $s['targeted'] );
		$this->assertSame( 1, $s['full'] );
		$this->assertSame( 67, $s['targeted_share'] );
		$this->assertSame( 10, $s['urls'] );
		$this->assertSame( 1, $s['problems'] );
		$this->assertSame( 1, $s['skipped'] );
		$this->assertSame( 3, $s['layer_purges'] );
		$this->assertSame( 100, $s['avg_ms'] );
		$this->assertSame( array( 'auto' => 3 ), $s['by_source'] );
		$this->assertSame( array( 'post' => 3 ), $s['by_change'] );
	}

	public function test_every_day_of_the_range_is_present_even_without_flushes(): void {
		$s = Impact::summarise( array( $this->row( '2025-11-25', true ), $this->row( '2020-01-01', true ) ), self::NOW, 7 );
		$this->assertCount( 7, $s['per_day'] );
		$this->assertSame( '2025-11-19', $s['per_day'][0]['date'] );
		$this->assertSame( 1, $s['per_day'][6]['full'] );
		$this->assertSame( 0, $s['per_day'][0]['full'] );
	}

	public function test_empty_history_gives_zeros(): void {
		$s = Impact::summarise( array(), self::NOW, 30 );
		$this->assertSame( 0, $s['flushes'] );
		$this->assertSame( 0, $s['targeted_share'] );
		$this->assertSame( 0, $s['avg_ms'] );
		$this->assertCount( 30, $s['per_day'] );
	}
}
