<?php
namespace PurgeKit\Tests\Unit\Warm;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeStore;
use PurgeKit\Warm\SitemapReader;
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

	private function sitemapWarmer( FakeHttp $http ): Warmer {
		return new Warmer( $http, new FakeStore(), 'https://site.test/', 5, null, new SitemapReader( $http, 'https://site.test/' ) );
	}

	private function urlset( int $n ): string {
		$loc = '';
		for ( $i = 1; $i <= $n; $i++ ) {
			$loc .= "<url><loc>https://site.test/p$i/</loc></url>";
		}
		return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $loc . '</urlset>';
	}

	public function test_purge_everything_queues_sitemap_urls_without_fetching_during_the_flush(): void {
		$http = new FakeHttp();
		$http->queue( new HttpResponse( 200, $this->urlset( 3 ) ) );
		$w = $this->sitemapWarmer( $http );
		$w->enqueue( PurgePlan::everything(), 20 );
		$this->assertSame( array(), $http->requests, 'enqueue() is called inside the flush lock and must not do HTTP.' );
		$this->assertGreaterThan( 1, $w->pending() );
		$w->processBatch();
		$urls = array_column( $http->requests, 'url' );
		$this->assertSame( 'https://site.test/wp-sitemap.xml', $urls[0] );
		$this->assertSame( array( 'https://site.test/', 'https://site.test/p1/', 'https://site.test/p2/', 'https://site.test/p3/' ), array_slice( $urls, 1 ) );
	}

	public function test_url_plans_and_a_zero_limit_never_read_the_sitemap(): void {
		$http = new FakeHttp();
		$w    = $this->sitemapWarmer( $http );
		$w->enqueue( PurgePlan::forUrls( array( 'https://site.test/a' ) ), 20 );
		$w->enqueue( PurgePlan::everything(), 0 );
		$w->processBatch();
		$this->assertNotContains( 'https://site.test/wp-sitemap.xml', array_column( $http->requests, 'url' ) );
	}

	public function test_sitemap_failure_still_warms_the_home_page(): void {
		$http = new FakeHttp();
		$http->queue( new HttpException( 'down' ) );
		$w = $this->sitemapWarmer( $http );
		$w->enqueue( PurgePlan::everything(), 20 );
		$w->processBatch();
		$this->assertSame(
			array( 'https://site.test/wp-sitemap.xml', 'https://site.test/sitemap_index.xml', 'https://site.test/sitemap.xml', 'https://site.test/sitemaps.xml', 'https://site.test/' ),
			array_column( $http->requests, 'url' )
		);
		$this->assertSame( 0, $w->pending() );
	}

	public function test_the_sitemap_is_read_only_once(): void {
		$http = new FakeHttp();
		$http->queue( new HttpResponse( 200, $this->urlset( 12 ) ) );
		$w = $this->sitemapWarmer( $http );
		$w->enqueue( PurgePlan::everything(), 50 );
		$w->processBatch();
		$w->processBatch();
		$this->assertSame( 1, count( array_keys( array_column( $http->requests, 'url' ), 'https://site.test/wp-sitemap.xml' ) ) );
	}
}
