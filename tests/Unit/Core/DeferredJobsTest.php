<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\DeferredJobs;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Tests\Fakes\FakeStore;

final class DeferredJobsTest extends TestCase {
	public function test_add_find_take(): void {
		$jobs = new DeferredJobs( new FakeStore() );
		$item = $jobs->add( 500, PurgePlan::forUrls( array( 'https://a.test/' ), array( 'p-1' ) ), array( 'cloudflare' ), array( 'Rule' ) );
		$this->assertSame( array( 'p-1' ), $jobs->find( $item['id'] )['tags'] );
		$this->assertSame( array( 'cloudflare' ), $jobs->take( $item['id'] )['layers'] );
		$this->assertNull( $jobs->take( $item['id'] ), 'A job runs once.' );
		$this->assertSame( array(), $jobs->all() );
	}

	public function test_the_list_is_capped(): void {
		$jobs = new DeferredJobs( new FakeStore() );
		for ( $i = 0; $i < DeferredJobs::MAX; $i++ ) {
			$this->assertNotNull( $jobs->add( 1, PurgePlan::everything(), null, array() ) );
		}
		$this->assertNull( $jobs->add( 1, PurgePlan::everything(), null, array() ) );
	}
}
