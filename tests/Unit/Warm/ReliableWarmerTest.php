<?php
namespace PurgeKit\Tests\Unit\Warm;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Clock;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeStore;
use PurgeKit\Tests\Fakes\FakeLock;
use PurgeKit\Warm\Warmer;

final class ReliableWarmerTest extends TestCase {
	private FakeStore $store;
	private FakeHttp $http;
	private Clock $clock;
	private Warmer $warmer;

	protected function setUp(): void {
		$this->store = new FakeStore();
		$this->http = new FakeHttp();
		$this->clock = new class() implements Clock {
			public int $seconds = 1000;
			public function now(): int { return $this->seconds * 1000; }
		};
		$this->warmer = new Warmer( $this->http, $this->store, 'https://site.test/', 2, null, null, $this->clock );
	}

	public function test_network_failures_back_off_and_stop_after_three_retries(): void {
		$this->http->queue( ...array_fill( 0, 4, new HttpException( 'timeout' ) ) );
		$this->warmer->enqueue( PurgePlan::forUrls( array( 'https://site.test/a' ) ) );
		$this->assertSame( 1, $this->warmer->processBatch() );
		$this->assertSame( 1015, $this->warmer->nextAt() );
		$this->assertSame( 0, $this->warmer->processBatch() );
		$this->clock->seconds = 1015;
		$this->warmer->processBatch();
		$this->assertSame( 1045, $this->warmer->nextAt() );
		$this->clock->seconds = 1045;
		$this->warmer->processBatch();
		$this->assertSame( 1105, $this->warmer->nextAt() );
		$this->clock->seconds = 1105;
		$this->warmer->processBatch();
		$this->assertSame( array( 'pending' => 0, 'next_at' => 1110, 'successful' => 0, 'failed' => 1, 'retries' => 3 ), $this->warmer->progress() );
		$this->assertSame( 4, count( $this->http->requests ) );
	}

	/** @dataProvider busyResponses */
	public function test_overload_pauses_the_entire_queue_and_honours_retry_after( int $status, string $header, int $until ): void {
		$this->http->queue( new HttpResponse( $status, '', array( 'Retry-After' => $header ) ) );
		$this->warmer->enqueue( PurgePlan::forUrls( array( 'https://site.test/a', 'https://site.test/b', 'https://site.test/c' ) ) );
		$this->warmer->processBatch();
		$this->assertSame( $until, $this->warmer->nextAt() );
		$this->assertSame( 0, $this->warmer->processBatch() );
		$this->clock->seconds = $until;
		$this->warmer->processBatch();
		$this->assertSame( 0, $this->warmer->pending() );
		$this->assertSame( 3, $this->warmer->progress()['successful'] );
	}

	public function busyResponses(): array {
		return array( array( 429, '60', 1060 ), array( 503, gmdate( 'D, d M Y H:i:s \G\M\T', 1090 ), 1090 ), array( 429, '999999', 4600 ), array( 503, 'invalid', 1015 ) );
	}

	public function test_permanent_errors_are_not_retried_and_progress_resets_for_next_cycle(): void {
		$this->http->queue( new HttpResponse( 404 ), new HttpResponse( 200 ) );
		$this->warmer->enqueue( PurgePlan::forUrls( array( 'https://site.test/missing', 'https://site.test/ok' ) ) );
		$this->warmer->processBatch();
		$this->assertSame( 1, $this->warmer->progress()['failed'] );
		$this->assertSame( 1, $this->warmer->progress()['successful'] );
		$this->assertSame( 0, $this->warmer->progress()['retries'] );
		$this->warmer->enqueue( PurgePlan::everything() );
		$this->assertSame( 0, $this->warmer->progress()['failed'] );
	}

	public function test_priority_urls_precede_other_urls_and_are_restricted_to_the_same_origin(): void {
		$this->store->set( 'warm_priority_urls', array( 'https://site.test/shop/', 'https://other.test/', 'https://user:pass@site.test/', 'https://site.test:123/' ) );
		$this->warmer->enqueue( PurgePlan::everything() );
		$this->warmer->processBatch();
		$this->assertSame( array( 'https://site.test/shop/', 'https://site.test/' ), array_column( $this->http->requests, 'url' ) );
		$this->assertSame( 0, $this->http->requests[0]['args']['redirection'] );
	}

	public function test_priority_configuration_does_not_add_unaffected_urls_to_targeted_warming(): void {
		$this->store->set( 'warm_priority_urls', array( 'https://site.test/shop/', 'https://site.test/b' ) );
		$this->warmer->enqueue( PurgePlan::forUrls( array( 'https://site.test/a', 'https://site.test/b' ) ) );
		$this->warmer->processBatch();
		$this->assertSame( array( 'https://site.test/b', 'https://site.test/a' ), array_column( $this->http->requests, 'url' ) );
	}

	public function test_busy_lock_does_not_consume_work(): void {
		$lock = new FakeLock();
		$lock->busy = true;
		$warmer = new Warmer( $this->http, $this->store, 'https://site.test/', 2, null, null, $this->clock, $lock );
		$warmer->enqueue( PurgePlan::everything() );
		$this->assertSame( 0, $warmer->processBatch() );
		$this->assertSame( 1, $warmer->pending() );
		$this->assertSame( array(), $this->http->requests );
	}
	public function test_a_later_busy_response_cannot_shorten_the_pause(): void {
		$this->http->queue( new HttpResponse( 429, '', array( 'Retry-After' => '120' ) ), new HttpResponse( 503, '', array( 'Retry-After' => '30' ) ) );
		$this->warmer->enqueue( PurgePlan::forUrls( array( 'https://site.test/a', 'https://site.test/b' ) ) );
		$this->warmer->processBatch();
		$this->assertSame( 1120, $this->warmer->nextAt() );
		$this->clock->seconds = 1120;
		$this->warmer->processBatch();
		$this->assertSame( 2, $this->warmer->progress()['successful'] );
		$this->assertSame( 0, $this->warmer->pending() );
	}

	public function test_worker_releases_its_lock_and_retains_work_on_an_unexpected_transport_exception(): void {
		$http = new class() implements \PurgeKit\Core\Http {
			public function request( string $method, string $url, array $args = array() ): HttpResponse { throw new \RuntimeException( 'broken' ); }
			public function requestMany( string $method, array $urls, array $args = array() ): array { throw new \RuntimeException( 'broken' ); }
		};
		$lock = new FakeLock();
		$w = new Warmer( $http, $this->store, 'https://site.test/', 2, null, null, $this->clock, $lock );
		$w->enqueue( PurgePlan::everything() );
		$this->assertSame( 1, $w->processBatch() );
		$this->assertSame( 1, $w->progress()['retries'] );
		$this->assertSame( 1, $lock->released );
		$this->assertSame( 1, $w->pending() );
	}

}
