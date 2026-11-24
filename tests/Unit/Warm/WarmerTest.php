<?php
namespace PurgeKit\Tests\Unit\Warm;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeStore;
use PurgeKit\Warm\Warmer;

final class WarmerTest extends TestCase {
	private function warmer( FakeHttp $http, ?callable $extra = null ): Warmer {
		return new Warmer( $http, new FakeStore(), 'https://site.test/', 5, $extra );
	}

	public function test_process_batch_fetches_at_most_5(): void {
		$http = new FakeHttp();
		$w    = $this->warmer( $http );
		$w->enqueue( PurgePlan::forUrls( array_map( static fn( $i ) => "https://site.test/$i", range( 1, 8 ) ) ) );
		$this->assertSame( 5, $w->processBatch() );
		$this->assertCount( 5, $http->requests );
		$this->assertSame( 3, $w->pending() );
		$this->assertSame( 3, $w->processBatch() );
		$this->assertSame( 0, $w->pending() );
	}

	public function test_batch_is_one_concurrent_round(): void {
		$http = new FakeHttp();
		$w    = $this->warmer( $http );
		$w->enqueue( PurgePlan::forUrls( array_map( static fn( $i ) => "https://site.test/$i", range( 1, 5 ) ) ) );
		$w->processBatch();
		$this->assertSame( 1, $http->batches );
		$this->assertCount( 5, $http->requests );
	}

	public function test_failed_url_is_dropped_not_retried(): void {
		$http = new FakeHttp();
		$http->queue( new HttpException( 'timeout' ) );
		$w = $this->warmer( $http );
		$w->enqueue( PurgePlan::forUrls( array( 'https://site.test/a', 'https://site.test/b' ) ) );
		$this->assertSame( 2, $w->processBatch() );
		$this->assertSame( 0, $w->pending() );
	}

	public function test_all_plan_warms_home_and_extra_urls(): void {
		$http = new FakeHttp();
		$w    = $this->warmer( $http, static fn() => array( 'https://site.test/shop/' ) );
		$w->enqueue( PurgePlan::everything() );
		$w->processBatch();
		$this->assertSame( array( 'https://site.test/', 'https://site.test/shop/' ), array_column( $http->requests, 'url' ) );
	}

	public function test_requests_are_marked_as_warmers_and_deduped(): void {
		$http = new FakeHttp();
		$w    = $this->warmer( $http );
		$w->enqueue( PurgePlan::forUrls( array( 'https://site.test/a' ) ) );
		$w->enqueue( PurgePlan::forUrls( array( 'https://site.test/a' ) ) );
		$this->assertSame( 1, $w->pending() );
		$w->processBatch();
		$this->assertSame( 'GET', $http->requests[0]['method'] );
		$this->assertSame( '1', $http->requests[0]['args']['headers']['X-PurgeKit-Warm'] );
	}

	public function test_empty_queue_processes_nothing(): void {
		$this->assertSame( 0, $this->warmer( new FakeHttp() )->processBatch() );
	}
}
