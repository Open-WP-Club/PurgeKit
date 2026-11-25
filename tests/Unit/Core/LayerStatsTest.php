<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Bytes;
use PurgeKit\Core\LayerStats;

final class LayerStatsTest extends TestCase {
	private function row( bool $all, string $at, array $results, int $urls = 0 ): array {
		return array(
			'all'        => $all,
			'created_at' => $at,
			'urls_total' => $urls,
			'results'    => $results,
		);
	}

	public function test_it_counts_purges_failures_time_and_pages_per_layer(): void {
		$rows = array(
			$this->row( true, '2026-11-20 10:00:00', array( array( 'layer' => 'cf', 'status' => 'ok', 'ms' => 100 ), array( 'layer' => 'op', 'status' => 'skipped', 'ms' => 0 ) ) ),
			$this->row( false, '2026-11-22 10:00:00', array( array( 'layer' => 'cf', 'status' => 'ok', 'ms' => 300 ) ), 4 ),
			$this->row( false, '2026-11-21 10:00:00', array( array( 'layer' => 'cf', 'status' => 'failed', 'ms' => 500 ) ), 2 ),
		);
		$s  = LayerStats::summarise( $rows );
		$cf = $s['cf'];
		$this->assertSame( 2, $cf['purges'] );
		$this->assertSame( 1, $cf['failed'] );
		$this->assertSame( 1, $cf['full'] );
		$this->assertSame( 4, $cf['urls'], 'Only successful targeted purges add pages.' );
		$this->assertSame( 300, $cf['avg_ms'] );
		$this->assertSame( 500, $cf['slowest_ms'] );
		$this->assertSame( 67, $cf['success_rate'] );
		$this->assertSame( '2026-11-22 10:00:00', $cf['last_at'] );
		$this->assertSame( 1, $s['op']['skipped'] );
		$this->assertNull( $s['op']['success_rate'], 'A layer that was only skipped has no success rate.' );
		$this->assertNull( $s['op']['last_at'] );
	}

	public function test_nothing_in_gives_nothing_out(): void {
		$this->assertSame( array(), LayerStats::summarise( array() ) );
	}

	public function test_sizes_are_readable(): void {
		$this->assertSame( '0 B', Bytes::human( 0 ) );
		$this->assertSame( '512 B', Bytes::human( 512 ) );
		$this->assertSame( '1.5 KB', Bytes::human( 1536 ) );
		$this->assertSame( '128 MB', Bytes::human( 134217728 ) );
		$this->assertSame( '2.0 GB', Bytes::human( 2 * 1024 ** 3 ) );
	}
}
