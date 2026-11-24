<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\Executor;
use PurgeKit\Core\Flusher;
use PurgeKit\Core\Planner;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Queue;
use PurgeKit\Core\Run;
use PurgeKit\Core\UrlExpander;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeHistory;
use PurgeKit\Tests\Fakes\FakeLayer;
use PurgeKit\Tests\Fakes\FakeLock;
use PurgeKit\Tests\Fakes\FakeStore;

final class FlusherTest extends TestCase {
	private Queue $queue;
	private FakeLock $lock;
	private FakeHistory $history;
	private FakeLayer $layer;
	private array $warmed = array();

	private function flusher( ?\Throwable $layer_throws = null ): Flusher {
		$this->queue   = new Queue( new FakeStore() );
		$this->lock    = new FakeLock();
		$this->history = new FakeHistory();
		$this->layer   = new FakeLayer( 'a', 10, true, $layer_throws );
		$expander      = new class() implements UrlExpander {
			public function expand( Change $change ): array {
				return array();
			}
		};
		return new Flusher(
			$this->queue,
			new Planner( $expander, 'https://site.test' ),
			new Executor( new Registry( array( $this->layer ) ), new FakeClock() ),
			$this->lock,
			$this->history,
			function ( PurgePlan $p ) {
				$this->warmed[] = $p;
			}
		);
	}

	public function test_locked_returns_locked_and_does_nothing(): void {
		$f = $this->flusher();
		$this->queue->add( Change::siteWide( 'menu' ) );
		$this->lock->busy = true;
		$run              = $f->flush( 'cron' );
		$this->assertSame( Run::LOCKED, $run->status );
		$this->assertSame( 1, $this->queue->count() );
		$this->assertSame( array(), $this->layer->calls );
		$this->assertSame( array(), $this->history->rows );
	}

	public function test_empty_queue_skips_and_records(): void {
		$f   = $this->flusher();
		$run = $f->flush( 'cron' );
		$this->assertSame( Run::SKIPPED, $run->status );
		$this->assertSame( array(), $this->layer->calls );
		$this->assertCount( 1, $this->history->rows );
		$this->assertSame( array(), $this->warmed );
	}

	public function test_force_flush_runs_everything_even_if_queue_empty(): void {
		$f   = $this->flusher();
		$run = $f->flush( 'manual', true );
		$this->assertSame( Run::OK, $run->status );
		$this->assertSame( array( array( 'all', array() ) ), $this->layer->calls );
	}

	public function test_lock_released_after_executor_exception(): void {
		$f = $this->flusher( new \RuntimeException( 'x' ) );
		$f->flushAll( 'manual' );
		$this->assertSame( 1, $this->lock->released );
	}

	public function test_warm_called_with_plan_after_success(): void {
		$f = $this->flusher();
		$f->flushUrls( 'webhook', array( 'https://site.test/a' ) );
		$this->assertCount( 1, $this->warmed );
		$this->assertSame( array( 'https://site.test/a' ), $this->warmed[0]->urls );
	}

	public function test_queued_changes_become_plan_and_are_recorded(): void {
		$f = $this->flusher();
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/p' ) ) );
		$f->flush( 'auto' );
		$this->assertSame( array( array( 'urls', array( 'https://site.test/p' ) ) ), $this->layer->calls );
		$this->assertSame( 'post', $this->history->rows[0][2][0]->type );
		$this->assertSame( 0, $this->queue->count() );
	}

	public function test_flush_all_also_clears_pending_queue(): void {
		$f = $this->flusher();
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/p' ) ) );
		$f->flushAll( 'manual' );
		$this->assertSame( 0, $this->queue->count() );
		$this->assertSame( array( array( 'all', array() ) ), $this->layer->calls );
	}
}
