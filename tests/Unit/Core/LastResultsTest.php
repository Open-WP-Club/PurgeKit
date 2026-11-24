<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LastResults;
use PurgeKit\Layers\Tier;

final class LastResultsTest extends TestCase {
	private function row( string $at, string $source, array $results ): array {
		return array(
			'created_at' => $at,
			'source'     => $source,
			'results'    => $results,
		);
	}

	public function test_newest_real_result_per_layer_wins(): void {
		$out = LastResults::fromRows(
			array(
				$this->row( '2026-11-24 12:00:00', 'cron', array( array( 'layer' => 'cf', 'status' => 'failed', 'message' => 'bad token', 'ms' => 9 ) ) ),
				$this->row( '2026-11-24 11:00:00', 'cli', array( array( 'layer' => 'cf', 'status' => 'ok' ), array( 'layer' => 'obj', 'status' => 'ok', 'ms' => 2 ) ) ),
			)
		);
		$this->assertSame( 'failed', $out['cf']['status'] );
		$this->assertSame( 'bad token', $out['cf']['message'] );
		$this->assertSame( 'cron', $out['cf']['source'] );
		$this->assertSame( '2026-11-24 12:00:00', $out['cf']['at'] );
		$this->assertSame( 'ok', $out['obj']['status'] );
		$this->assertSame( 2, $out['obj']['ms'] );
	}

	public function test_skipped_results_do_not_hide_an_older_real_one(): void {
		$out = LastResults::fromRows(
			array(
				$this->row( '2026-11-24 12:00:00', 'cron', array( array( 'layer' => 'cf', 'status' => 'skipped' ) ) ),
				$this->row( '2026-11-24 11:00:00', 'cron', array( array( 'layer' => 'cf', 'status' => 'ok' ) ) ),
			)
		);
		$this->assertSame( '2026-11-24 11:00:00', $out['cf']['at'] );
	}

	public function test_malformed_rows_are_ignored(): void {
		$this->assertSame(
			array(),
			LastResults::fromRows( array( array(), array( 'results' => 'junk' ), array( 'results' => array( 'x', array( 'status' => 'ok' ), array( 'layer' => 'a' ) ) ) ) )
		);
	}

	public function test_tier_labels(): void {
		$this->assertSame( 'PHP code cache', Tier::label( Tier::OPCACHE ) );
		$this->assertSame( 'Object cache', Tier::label( Tier::OBJECT ) );
		$this->assertSame( 'Cache plugin', Tier::label( Tier::PLUGIN ) );
		$this->assertSame( 'Host cache', Tier::label( Tier::HOST ) );
		$this->assertSame( 'Reverse proxy', Tier::label( Tier::PROXY ) );
		$this->assertSame( 'CDN / edge', Tier::label( Tier::CDN ) );
	}
}
