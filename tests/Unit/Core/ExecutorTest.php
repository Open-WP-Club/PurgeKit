<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Executor;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeLayer;

final class ExecutorTest extends TestCase {
	public function test_runs_layers_inside_to_outside(): void {
		$log = new \ArrayObject();
		$reg = new Registry( array( new FakeLayer( 'cdn', 50, true, null, 'ok', $log ), new FakeLayer( 'obj', 10, true, null, 'ok', $log ), new FakeLayer( 'host', 30, true, null, 'ok', $log ) ) );
		( new Executor( $reg, new FakeClock() ) )->run( PurgePlan::everything() );
		$this->assertSame( array( 10, 30, 50 ), $log->getArrayCopy() );
	}

	public function test_throwing_layer_is_failed_and_run_continues_partial(): void {
		$log = new \ArrayObject();
		$reg = new Registry( array( new FakeLayer( 'a', 10, true, new \RuntimeException( 'boom' ), 'ok', $log ), new FakeLayer( 'b', 20, true, null, 'ok', $log ) ) );
		$run = ( new Executor( $reg, new FakeClock() ) )->run( PurgePlan::everything() );
		$this->assertSame( Run::PARTIAL, $run->status );
		$this->assertSame( LayerResult::FAILED, $run->results[0]->status );
		$this->assertSame( 'boom', $run->results[0]->message );
		$this->assertSame( 'a', $run->results[0]->layer );
		$this->assertSame( array( 10, 20 ), $log->getArrayCopy() );
	}

	public function test_empty_plan_calls_no_layer(): void {
		$layer = new FakeLayer( 'a', 10 );
		$run   = ( new Executor( new Registry( array( $layer ) ), new FakeClock() ) )->run( PurgePlan::forUrls( array() ) );
		$this->assertSame( Run::SKIPPED, $run->status );
		$this->assertSame( array(), $layer->calls );
	}

	public function test_no_active_layers_is_skipped(): void {
		$run = ( new Executor( new Registry( array( new FakeLayer( 'a', 10, false ) ) ), new FakeClock() ) )->run( PurgePlan::everything() );
		$this->assertSame( Run::SKIPPED, $run->status );
	}

	public function test_url_plan_calls_purge_urls_not_purge_all(): void {
		$layer = new FakeLayer( 'a', 10 );
		( new Executor( new Registry( array( $layer ) ), new FakeClock() ) )->run( PurgePlan::forUrls( array( 'https://a.test/x' ) ) );
		$this->assertSame( array( array( 'urls', array( 'https://a.test/x' ) ) ), $layer->calls );
	}

	public function test_records_duration_from_clock(): void {
		$run = ( new Executor( new Registry( array( new FakeLayer( 'a', 10 ) ) ), new FakeClock() ) )->run( PurgePlan::everything() );
		$this->assertSame( 5, $run->results[0]->ms );
	}
}
