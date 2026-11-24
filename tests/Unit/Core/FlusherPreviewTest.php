<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\Executor;
use PurgeKit\Core\Flusher;
use PurgeKit\Core\Planner;
use PurgeKit\Core\Queue;
use PurgeKit\Core\RuleSet;
use PurgeKit\Core\RuleStore;
use PurgeKit\Core\Run;
use PurgeKit\Core\UrlExpander;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeHistory;
use PurgeKit\Tests\Fakes\FakeLayer;
use PurgeKit\Tests\Fakes\FakeLock;
use PurgeKit\Tests\Fakes\FakeStore;

/** The dry run (preview) and the post-flush verification hook of the Flusher. */
final class FlusherPreviewTest extends TestCase {
	private Queue $queue;
	private FakeHistory $history;
	private FakeLock $lock;
	private FakeLayer $obj;
	private FakeLayer $cdn;

	private function flusher( ?array $rules = null, ?callable $verify = null, string $status = 'ok' ): Flusher {
		$expander      = new class() implements UrlExpander {
			public function expand( Change $change ): array {
				return array();
			}
		};
		$store         = null;
		$this->queue   = new Queue( new FakeStore() );
		$this->history = new FakeHistory();
		$this->lock    = new FakeLock();
		$this->obj     = new FakeLayer( 'obj', 10, true, null, $status );
		$this->cdn     = new FakeLayer( 'cdn', 50, true, null, $status );
		if ( null !== $rules ) {
			$store = new RuleStore( new FakeStore() );
			$store->save( RuleSet::fromArray( $rules ) );
		}
		return new Flusher(
			$this->queue,
			new Planner( $expander, 'https://site.test' ),
			new Executor( new Registry( array( $this->obj, $this->cdn ) ), new FakeClock() ),
			$this->lock,
			$this->history,
			static function () {},
			null,
			$store,
			$verify
		);
	}

	public function test_preview_does_not_drain_or_execute(): void {
		$f = $this->flusher();
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/a' ) ) );
		$p = $f->preview();
		$this->assertSame( 1, $this->queue->count() );
		$this->assertSame( array(), $this->obj->calls );
		$this->assertSame( array(), $this->history->rows );
		$this->assertSame( 1, $p['pending'] );
		$this->assertFalse( $p['empty'] );
		$this->assertFalse( $p['all'] );
		$this->assertSame( array( 'https://site.test/a' ), $p['urls'] );
		$this->assertSame( array( 'obj', 'cdn' ), array_column( $p['jobs'][0]['layers'], 'id' ) );
		$this->assertSame( array(), $p['jobs'][0]['rules'] );
	}

	public function test_preview_of_an_empty_queue_is_empty_unless_forced(): void {
		$f = $this->flusher();
		$this->assertTrue( $f->preview()['empty'] );
		$forced = $f->preview( true );
		$this->assertTrue( $forced['all'] );
		$this->assertFalse( $forced['empty'] );
	}

	public function test_preview_escalates_site_wide_changes_like_flush(): void {
		$f = $this->flusher();
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/a' ) ) );
		$this->queue->add( Change::siteWide( 'switch_theme' ) );
		$p = $f->preview();
		$this->assertTrue( $p['all'] );
		$this->assertSame( 2, $p['pending'], 'The pending count is the raw queue, not the escalated one.' );
		$this->assertSame( 2, $this->queue->count() );
	}

	public function test_preview_caps_the_listed_urls_but_reports_the_total(): void {
		$f    = $this->flusher();
		$urls = array_map( static fn( $i ) => "https://site.test/$i", range( 1, 120 ) );
		$this->queue->add( Change::forUrls( 'post', $urls ) );
		$p = $f->preview();
		$this->assertCount( Flusher::PREVIEW_URL_CAP, $p['urls'] );
		$this->assertSame( 120, $p['urls_total'] );
		$this->assertCount( Flusher::PREVIEW_URL_CAP, $p['jobs'][0]['urls'] );
		$this->assertSame( 120, $p['jobs'][0]['urls_total'] );
	}

	public function test_preview_names_rules_layers_and_skipped_changes(): void {
		$f = $this->flusher(
			array(
				array( 'name' => 'Posts to the CDN', 'on' => array( 'post' ), 'layers' => array( 'cdn' ), 'action' => 'default' ),
				array( 'name' => 'Quiet comments', 'on' => array( 'comment' ), 'action' => 'skip' ),
			)
		);
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/a' ) ) );
		$this->queue->add( Change::forUrls( 'comment', array( 'https://site.test/c' ) ) );
		$this->queue->add( Change::forUrls( 'term', array( 'https://site.test/t' ) ) );
		$p = $f->preview();

		$this->assertTrue( $p['routed'] );
		$this->assertCount( 2, $p['jobs'] );
		$this->assertSame( array( 'Posts to the CDN' ), $p['jobs'][0]['rules'] );
		$this->assertSame( array( 'cdn' ), array_column( $p['jobs'][0]['layers'], 'id' ) );
		$this->assertSame( array(), $p['jobs'][1]['rules'] );
		$this->assertSame( array( 'obj', 'cdn' ), array_column( $p['jobs'][1]['layers'], 'id' ) );
		$this->assertSame(
			array( array( 'type' => 'comment', 'rule' => 'Quiet comments', 'urls' => array( 'https://site.test/c' ) ) ),
			$p['skipped']
		);
		$this->assertSame( 3, $this->queue->count() );
		$this->assertSame( array(), $this->cdn->calls );
	}

	public function test_preview_matches_what_flush_then_does(): void {
		$f = $this->flusher( array( array( 'name' => 'All on CDN', 'on' => array( 'post' ), 'layers' => array( 'cdn' ), 'action' => 'all' ) ) );
		$this->queue->add( Change::forUrls( 'post', array( 'https://site.test/a' ) ) );
		$p = $f->preview();
		$this->assertTrue( $p['all'] );
		$f->flush( 'cron' );
		$this->assertSame( array( array( 'all', array() ) ), $this->cdn->calls );
		$this->assertSame( array(), $this->obj->calls );
	}

	public function test_verification_runs_after_the_lock_is_released_and_is_recorded(): void {
		$seen = array();
		$f    = $this->flusher(
			null,
			function ( Run $run ) use ( &$seen ) {
				$seen[] = array( 'released' => $this->lock->released, 'rows' => count( $this->history->rows ) );
				return array( 'status' => 'verified', 'message' => 'ok' );
			}
		);
		$f->flushAll( 'manual' );
		$this->assertSame( array( array( 'released' => 1, 'rows' => 1 ) ), $seen );
		$this->assertSame( array( 1 => array( 'status' => 'verified', 'message' => 'ok' ) ), $this->history->annotations );
	}

	public function test_verification_is_skipped_for_skipped_failed_and_locked_runs(): void {
		$calls = 0;
		$hook  = static function () use ( &$calls ) {
			++$calls;
			return array( 'status' => 'verified' );
		};
		$f = $this->flusher( null, $hook );
		$f->flush( 'cron' ); // Empty queue: skipped.
		$this->lock->busy = true;
		$f->flushAll( 'manual' ); // Locked.
		$this->assertSame( 0, $calls );

		$f = $this->flusher( null, $hook, 'failed' ); // Every layer fails.
		$this->assertSame( Run::FAILED, $f->flushAll( 'manual' )->status );
		$this->assertSame( 0, $calls );

		$f = $this->flusher( null, $hook );
		$f->flushAll( 'manual' );
		$this->assertSame( 1, $calls );
	}

	public function test_a_verification_error_never_breaks_the_flush(): void {
		$f   = $this->flusher( null, static function () {
			throw new \RuntimeException( 'network down' );
		} );
		$run = $f->flushAll( 'manual' );
		$this->assertSame( Run::OK, $run->status );
		$this->assertSame( array(), $this->history->annotations );
		$this->assertFalse( $this->lock->busy );
	}
}
