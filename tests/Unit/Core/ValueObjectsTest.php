<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;

final class ValueObjectsTest extends TestCase {
	private function plan(): PurgePlan {
		return PurgePlan::everything();
	}

	public function test_site_wide_change_has_no_urls(): void {
		$c = Change::siteWide( 'menu' );
		$this->assertTrue( $c->site_wide );
		$this->assertSame( array(), $c->urls );
	}

	public function test_for_urls_change_is_not_site_wide(): void {
		$c = Change::forUrls( 'post', array( 'https://a.test/x' ) );
		$this->assertFalse( $c->site_wide );
		$this->assertSame( array( 'https://a.test/x' ), $c->urls );
	}

	public function test_empty_plan_is_empty(): void {
		$this->assertTrue( PurgePlan::forUrls( array() )->isEmpty() );
		$this->assertFalse( PurgePlan::everything()->isEmpty() );
		$this->assertFalse( PurgePlan::forUrls( array( 'https://a.test/' ) )->isEmpty() );
	}

	public function test_run_ok_when_no_failures(): void {
		$r = Run::fromResults( array( new LayerResult( 'a', LayerResult::OK ), new LayerResult( 'b', LayerResult::SKIPPED ) ), $this->plan() );
		$this->assertSame( Run::OK, $r->status );
	}

	public function test_run_partial_when_one_of_two_failed(): void {
		$r = Run::fromResults( array( new LayerResult( 'a', LayerResult::OK ), new LayerResult( 'b', LayerResult::FAILED ) ), $this->plan() );
		$this->assertSame( Run::PARTIAL, $r->status );
	}

	public function test_run_failed_when_all_failed(): void {
		$r = Run::fromResults( array( new LayerResult( 'a', LayerResult::FAILED ), new LayerResult( 'b', LayerResult::FAILED ) ), $this->plan() );
		$this->assertSame( Run::FAILED, $r->status );
	}

	public function test_run_ok_when_all_skipped(): void {
		$r = Run::fromResults( array( new LayerResult( 'a', LayerResult::SKIPPED ) ), $this->plan() );
		$this->assertSame( Run::OK, $r->status );
	}
}
