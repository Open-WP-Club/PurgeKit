<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\FailureStreaks;
use PurgeKit\Core\LayerResult;

final class FailureStreaksTest extends TestCase {
	private function failed( string $id = 'cf' ): LayerResult {
		return new LayerResult( $id, LayerResult::FAILED, 'Rate limited' );
	}

	public function test_it_crosses_the_limit_exactly_once(): void {
		$streaks = array();
		$crossed = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$step    = FailureStreaks::advance( $streaks, array( $this->failed() ) );
			$streaks = $step['streaks'];
			$crossed[ $i ] = $step['crossed'];
		}
		$this->assertSame( array(), $crossed[2] );
		$this->assertSame( 3, $crossed[3]['cf']['count'] );
		$this->assertSame( 'Rate limited', $crossed[3]['cf']['message'] );
		$this->assertSame( array(), $crossed[4], 'No repeat alert while it keeps failing.' );
		$this->assertSame( 5, $streaks['cf'] );
	}

	public function test_a_success_resets_and_a_skip_changes_nothing(): void {
		$step = FailureStreaks::advance( array( 'cf' => 2 ), array( new LayerResult( 'cf', LayerResult::SKIPPED, 'cli' ) ) );
		$this->assertSame( array( 'cf' => 2 ), $step['streaks'] );
		$step = FailureStreaks::advance( $step['streaks'], array( new LayerResult( 'cf', LayerResult::OK ) ) );
		$this->assertSame( array(), $step['streaks'] );
		$again = FailureStreaks::advance( array( 'cf' => 2 ), array( $this->failed(), $this->failed( 'other' ) ) );
		$this->assertSame( array( 'cf' ), array_keys( $again['crossed'] ) );
		$this->assertSame( 1, $again['streaks']['other'] );
	}
}
